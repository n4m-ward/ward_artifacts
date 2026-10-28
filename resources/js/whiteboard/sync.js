// Ward Whiteboard: sincronização do quadro com o servidor, por consulta curta.
// - Ao vivo: GET liveUrl?since=SEQ&p=ASSINATURA → 204, só presença, ou os elementos novos.
// - Envio: o onChange do Excalidraw marca o que tem version maior que a última
//   que o servidor conhece e manda em lote (POST /w/{uuid}/elements).
// - Junta o que chega com reconcileElements e aplica sem entrar no desfazer.
import { CaptureUpdateAction, newElementWith, reconcileElements, restoreElements } from '@excalidraw/excalidraw';

const PUSH_WAIT = 300; // ms depois da última mudança
const PUSH_MAX_WAIT = 600; // ms no máximo durante um arraste
const POLL_ALONE = 1500;
const POLL_TOGETHER = 700; // com outra pessoa no quadro
const POLL_HIDDEN = 10000;
const PRESENCE_EVERY = 400;
const PRESENCE_HEARTBEAT = 8000;
const MAX_CHUNK_BYTES = 1_500_000;

export function createHttp(config) {
    return async function http(url, { method = 'GET', body, keepalive = false } = {}) {
        const response = await fetch(url, {
            method,
            keepalive,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
                ...(config.csrf ? { 'X-CSRF-TOKEN': config.csrf } : {}),
            },
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });
        if (response.status === 204) return null;
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(data.message || `Erro ${response.status}`);
            error.status = response.status;
            throw error;
        }
        return data;
    };
}

/** Elemento gerado no servidor (IA, templates) que ainda precisa das medidas reais do texto. */
export const isPending = (element) => Boolean(element.customData?.wardPending);

export class WhiteboardSync {
    /**
     * handlers: onLive({session, comments}), onPresence(people, ai), onStatus(text), onError(message),
     * onReadOnly(), onApplied(elements).
     */
    constructor(config, http, handlers) {
        this.config = config;
        this.http = http;
        this.handlers = handlers;
        this.seq = config.initial.seq;
        this.signature = config.initial.presence;
        this.sent = new Map(); // id -> version que o servidor já tem
        this.fileUrls = { ...(config.initial.files || {}) };
        this.loadingFiles = new Set();
        this.uploadedFiles = new Set(Object.keys(this.fileUrls));
        this.canEdit = Boolean(config.canEdit && config.routes?.elements);
        this.together = (config.initial.people || []).length > 0;
        this.stopped = false;
        this.lastPresence = 0;
    }

    /** Elementos iniciais (já restaurados) que o servidor tem. */
    markSynced(elements) {
        for (const element of elements) this.sent.set(element.id, element.version);
    }

    attach(api) {
        this.api = api;
        // A cena inicial entra logo depois do api ficar pronto: as imagens vêm em seguida.
        setTimeout(() => this.loadFiles(), 100);
        this.refreshPending();
        this.schedulePoll(POLL_ALONE);
        this.heartbeat = setInterval(() => this.sendPresence(true), PRESENCE_HEARTBEAT);
        this.onVisibility = () => {
            if (document.visibilityState === 'visible') this.pollNow();
        };
        document.addEventListener('visibilitychange', this.onVisibility);
        this.onLeave = () => this.leave();
        window.addEventListener('pagehide', this.onLeave);
        this.sendPresence(true);
    }

    destroy() {
        this.stopped = true;
        clearTimeout(this.pollTimer);
        clearTimeout(this.pushTimer);
        clearInterval(this.heartbeat);
        document.removeEventListener('visibilitychange', this.onVisibility);
        window.removeEventListener('pagehide', this.onLeave);
    }

    // ---------- ao vivo ----------

    schedulePoll(wait) {
        clearTimeout(this.pollTimer);
        if (this.stopped) return;
        this.pollTimer = setTimeout(() => this.poll(), wait);
    }

    pollNow() {
        this.schedulePoll(0);
    }

    async poll() {
        if (this.polling) return;
        this.polling = true;
        try {
            const data = await this.fetchLive();
            if (data === null) {
                this.handlers.onStatus?.(null);
                return;
            }
            this.signature = data.presence;
            this.together = (data.people || []).length > 0;
            this.handlers.onPresence(data.people || [], data.ai || null);
            if (data.unchanged) return;

            if (data.files) Object.assign(this.fileUrls, data.files);
            for (const id of Object.keys(data.files || {})) this.uploadedFiles.add(id);
            this.applyRemote(data.elements || [], Boolean(data.full));
            this.seq = data.seq;
            this.handlers.onLive({ session: data.session || {}, comments: data.comments ?? null });
            this.loadFiles();
            this.handlers.onStatus?.(null);
        } catch {
            this.handlers.onStatus?.('Sem conexão. Tentando de novo…');
        } finally {
            this.polling = false;
            this.loadFiles();
            const hidden = document.visibilityState === 'hidden';
            this.schedulePoll(hidden ? POLL_HIDDEN : this.together ? POLL_TOGETHER : POLL_ALONE);
        }
    }

    /**
     * Busca o ao vivo pela URL assinada; no MCP App, se o host bloquear o fetch,
     * pela ferramenta whiteboard_live (fallbackPoll). null = nada mudou.
     */
    async fetchLive() {
        if (!this.directFailed) {
            try {
                const url = new URL(this.config.liveUrl, window.location.href);
                url.searchParams.set('since', String(this.seq));
                url.searchParams.set('p', this.signature || '');
                const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
                if (response.status === 403 && !this.fallbackPoll) {
                    this.handlers.onError('O acesso a este quadro acabou (o link venceu ou o convite foi removido). Recarregue a página.');
                    this.stopped = true;
                    return null;
                }
                if (response.status === 204) return null;
                if (response.ok) return await response.json();
                if (!this.fallbackPoll) throw new Error(String(response.status));
            } catch (error) {
                if (!this.fallbackPoll) throw error;
            }
            this.directFailed = true;
        }
        return this.fallbackPoll(this.seq, this.signature || '');
    }

    /** Junta elementos vindos do servidor com os locais (vence a version maior). */
    applyRemote(elements, full = false) {
        if (!this.api) return;
        const restored = restoreElements(elements, null, { repairBindings: false });
        for (const element of restored) {
            this.sent.set(element.id, Math.max(this.sent.get(element.id) ?? 0, element.version));
        }

        let local = this.api.getSceneElementsIncludingDeleted();
        if (full) {
            // O servidor mandou o quadro inteiro: o que não veio e já estava sincronizado foi apagado lá.
            const ids = new Set(restored.map((element) => element.id));
            local = local.filter((element) => ids.has(element.id) || (this.sent.get(element.id) ?? 0) < element.version);
        }

        if (!restored.length && !full) return;
        const merged = reconcileElements(local, restored, this.api.getAppState());
        this.api.updateScene({ elements: merged, captureUpdate: CaptureUpdateAction.NEVER });
        this.handlers.onApplied?.(restored);
        if (restored.some(isPending)) this.refreshPending();
    }

    /**
     * Elementos que a IA ou um template gravaram com medidas estimadas: o Excalidraw
     * mede o texto de verdade, repara as ligações, e a versão nova volta ao servidor.
     */
    async refreshPending() {
        if (!this.canEdit || !this.api) return;
        clearTimeout(this.pendingTimer);
        this.pendingTimer = setTimeout(async () => {
            try {
                await document.fonts?.load?.('20px Nunito');
            } catch {
                // sem a fonte, mede com a substituta
            }
            const all = this.api.getSceneElementsIncludingDeleted();
            const pending = new Set(all.filter((element) => isPending(element) && !element.isDeleted).map((element) => element.id));
            if (!pending.size) return;
            const refreshed = restoreElements(all, null, { refreshDimensions: true, repairBindings: true });
            const next = refreshed.map((element) => {
                if (!pending.has(element.id)) return element;
                // customData sempre como objeto novo: com undefined o newElementWith não sobe a versão.
                const { wardPending, ...customData } = element.customData || {};
                return newElementWith(element, { customData });
            });
            this.api.updateScene({ elements: next, captureUpdate: CaptureUpdateAction.NEVER });
            this.schedulePush();
        }, 250);
    }

    // ---------- envio ----------

    /** Chamado no onChange do Excalidraw. */
    changed(elements) {
        if (!this.canEdit) return;
        for (const element of elements) {
            if ((this.sent.get(element.id) ?? 0) < element.version) {
                this.schedulePush();
                return;
            }
        }
    }

    schedulePush() {
        clearTimeout(this.pushTimer);
        const now = Date.now();
        this.firstDirty ??= now;
        const wait = Math.max(0, Math.min(PUSH_WAIT, this.firstDirty + PUSH_MAX_WAIT - now));
        this.pushTimer = setTimeout(() => this.push(), wait);
        this.handlers.onStatus?.('Salvando…');
    }

    async push() {
        if (!this.api || !this.canEdit || this.stopped) return;
        if (this.pushing) {
            this.pushAgain = true;
            return;
        }
        this.firstDirty = null;
        const changed = this.api.getSceneElementsIncludingDeleted().filter((element) => {
            const known = this.sent.get(element.id);
            // Criado e apagado antes de chegar ao servidor: não precisa ir.
            if (known === undefined && element.isDeleted) return false;
            return (known ?? 0) < element.version;
        });
        if (!changed.length) {
            this.handlers.onStatus?.(null);
            return;
        }

        this.pushing = true;
        try {
            await this.uploadFiles(changed);
            for (const chunk of this.chunks(changed)) {
                const versions = new Map(chunk.map((element) => [element.id, element.version]));
                try {
                    const result = await this.http(this.config.routes.elements, { method: 'POST', body: { elements: chunk } });
                    for (const [id, version] of versions) this.sent.set(id, Math.max(this.sent.get(id) ?? 0, version));
                    if (result?.stale?.length) this.applyRemote(result.stale);
                } catch (error) {
                    if (error.status === 403) {
                        this.canEdit = false;
                        this.handlers.onReadOnly();
                        return;
                    }
                    if (error.status === 422 || error.status === 413) {
                        // Recusado: não fica tentando de novo; avisa.
                        for (const [id, version] of versions) this.sent.set(id, version);
                        this.handlers.onError(error.message);
                        continue;
                    }
                    throw error;
                }
            }
            this.handlers.onStatus?.(null);
        } catch {
            this.handlers.onStatus?.('Não salvou (sem conexão). Tentando de novo…');
            setTimeout(() => this.schedulePush(), 3000);
        } finally {
            this.pushing = false;
            if (this.pushAgain) {
                this.pushAgain = false;
                this.schedulePush();
            }
        }
    }

    chunks(elements) {
        const chunks = [];
        let current = [];
        let size = 0;
        for (const element of elements) {
            const bytes = JSON.stringify(element).length;
            if (current.length && (size + bytes > MAX_CHUNK_BYTES || current.length >= 1000)) {
                chunks.push(current);
                current = [];
                size = 0;
            }
            current.push(element);
            size += bytes;
        }
        if (current.length) chunks.push(current);
        return chunks;
    }

    /** Imagens novas (coladas ou arrastadas) vão antes dos elementos que as usam. */
    async uploadFiles(elements) {
        if (!this.config.routes.files) return;
        const files = this.api.getFiles();
        for (const element of elements) {
            if (element.type !== 'image' || element.isDeleted || !element.fileId || this.uploadedFiles.has(element.fileId)) continue;
            const file = files[element.fileId];
            if (!file?.dataURL) continue;
            try {
                await this.http(this.config.routes.files, { method: 'POST', body: { id: element.fileId, dataURL: file.dataURL } });
                this.uploadedFiles.add(element.fileId);
            } catch (error) {
                this.uploadedFiles.add(element.fileId);
                this.handlers.onError(error.message || 'A imagem não foi enviada.');
            }
        }
    }

    /** Busca as imagens que o quadro usa e ainda não estão carregadas. */
    async loadFiles() {
        if (!this.api) return;
        const have = this.api.getFiles();
        const wanted = this.api.getSceneElements()
            .filter((element) => element.type === 'image' && element.fileId && !have[element.fileId] && this.fileUrls[element.fileId] && !this.loadingFiles.has(element.fileId));
        for (const element of wanted) {
            const id = element.fileId;
            this.loadingFiles.add(id);
            try {
                const response = await fetch(this.fileUrls[id], { credentials: 'same-origin' });
                if (!response.ok) throw new Error(String(response.status));
                const blob = await response.blob();
                const dataURL = await new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onload = () => resolve(reader.result);
                    reader.onerror = reject;
                    reader.readAsDataURL(blob);
                });
                this.api.addFiles([{ id, mimeType: blob.type || 'image/png', dataURL, created: Date.now() }]);
            } catch {
                this.loadingFiles.delete(id);
            }
        }
    }

    // ---------- presença ----------

    pointer(payload) {
        this.lastPointer = { x: payload.pointer.x, y: payload.pointer.y };
        this.lastButton = payload.button;
        this.sendPresence();
    }

    sendPresence(force = false) {
        if (!this.config.routes?.presence || !this.api || this.stopped) return;
        const now = Date.now();
        if (!force && now - this.lastPresence < PRESENCE_EVERY) {
            clearTimeout(this.presenceTimer);
            this.presenceTimer = setTimeout(() => this.sendPresence(true), PRESENCE_EVERY - (now - this.lastPresence));
            return;
        }
        if (force && document.visibilityState === 'hidden') return;
        this.lastPresence = now;
        const state = this.api.getAppState();
        const zoom = state.zoom.value;
        this.http(this.config.routes.presence, {
            method: 'POST',
            body: {
                pointer: this.lastPointer ?? null,
                button: this.lastButton ?? 'up',
                selected: Object.keys(state.selectedElementIds || {}).slice(0, 50),
                view: { x: -state.scrollX, y: -state.scrollY, w: state.width / zoom, h: state.height / zoom },
                tool: state.activeTool?.type,
                editing: state.editingTextElement?.containerId ?? state.editingTextElement?.id ?? null,
            },
        }).catch(() => {});
    }

    leave() {
        if (!this.config.routes?.presence) return;
        this.http(this.config.routes.presence, { method: 'POST', body: { leave: true }, keepalive: true }).catch(() => {});
    }
}
