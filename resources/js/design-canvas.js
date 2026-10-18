// Ward Design: canvas ao vivo (pan/zoom, foco, Play, atualização por consulta curta).
// Na página do design, lê o estado inicial de <script id="ward-design-config" type="application/json">.
// O MCP App (resources/views/mcp/design-app.blade.php) embute este mesmo arquivo e cria o
// canvas com window.WardDesignCanvas, trocando a fonte do ao vivo e reagindo à seleção.
// Sem imports: o arquivo roda como está, também embutido no app.

const MIN_SCALE = 0.05;
const MAX_SCALE = 4;
const VISIBLE_MARGIN = 600; // px de tela além da borda em que o iframe já carrega
const POLL_VISIBLE = 1500;
const POLL_HIDDEN = 10000;
const MOBILE_BREAKPOINT = 640;

const NOTE_COLORS = {
    gray: '#E4E4E7', yellow: '#FEF3C7', orange: '#FFEDD5', red: '#FEE2E2', green: '#DCFCE7',
    teal: '#CCFBF1', blue: '#DBEAFE', purple: '#EDE9FE', pink: '#FCE7F3',
};

function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') node.className = value;
        else if (key === 'text') node.textContent = value;
        else if (key === 'style') Object.assign(node.style, value);
        else node.setAttribute(key, value === true ? '' : value);
    }
    for (const child of [].concat(children)) if (child) node.append(child);
    return node;
}

function storage(key, value) {
    try {
        if (value === undefined) return JSON.parse(localStorage.getItem(key));
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        return null;
    }
    return null;
}

class DesignCanvas {
    /**
     * options.poll(revision): estado novo, ou null se nada mudou (padrão: fetch em config.liveUrl).
     * options.onSelect(board | null): artboard selecionado ou aberto em foco.
     * options.isActive(): false pausa o ao vivo (ex.: app fora da tela).
     * options.persistView: guarda zoom/posição no localStorage (padrão: true).
     * options.mobileBreakpoint: abaixo desta largura vira lista (padrão: 640).
     * options.listMaxHeight: altura máxima de cada artboard na lista (padrão: sem limite).
     *
     * Um editor (resources/js/design-editor.js) pode se ligar em canvas.editor:
     * pointerDown(event) devolve true quando trata o clique; canvas.hold pausa o
     * ao vivo durante um arraste ou um envio.
     */
    constructor(root, config, options = {}) {
        this.root = root;
        this.config = config;
        this.options = {
            persistView: true, pollVisible: POLL_VISIBLE, mobileBreakpoint: MOBILE_BREAKPOINT, listMaxHeight: Infinity, ...options,
        };
        this.state = config.state;
        this.view = { x: 0, y: 0, scale: 1 };
        this.boards = new Map(); // id -> { data, node, frame, loaded }
        this.selected = null;
        this.focusIndex = null;
        this.viewKey = `ward-design-view:${config.design}`;
        this.editor = null;
        this.hold = false;

        this.viewport = root.querySelector('[data-canvas-viewport]');
        this.world = root.querySelector('[data-canvas-world]');
        this.notesLayer = root.querySelector('[data-canvas-notes]');
        this.boardsLayer = root.querySelector('[data-canvas-boards]');
        this.zoomLabel = root.querySelector('[data-zoom-label]');
        this.countLabel = document.querySelector('[data-board-count]');
        this.focus = document.querySelector('[data-focus]');
        this.empty = root.querySelector('[data-empty]');
        this.banner = root.querySelector('[data-banner]');
        this.list = root.querySelector('[data-canvas-list]');

        this.renderAll();
        this.bindCanvas();
        this.bindFocus();
        this.bindKeyboard();
        this.bindMessages();

        const saved = this.isMobile() || !this.options.persistView ? null : storage(this.viewKey);
        // Enquanto a pessoa não mexe no zoom/posição, o canvas se reenquadra quando chega artboard novo.
        this.userMoved = Boolean(saved);
        if (saved && typeof saved.scale === 'number' && saved.scale > 0) {
            this.view = saved;
            this.applyView();
        } else {
            this.fit();
        }

        this.schedulePoll();
        window.addEventListener('resize', () => this.applyView());
    }

    // ---------- render ----------

    renderAll() {
        this.renderNotes();
        const ids = new Set(this.state.boards.map((board) => board.id));

        for (const [id, entry] of this.boards) {
            if (!ids.has(id)) {
                entry.node.remove();
                entry.listNode?.remove();
                this.boards.delete(id);
            }
        }

        let added = false;
        for (const data of this.state.boards) {
            const entry = this.boards.get(data.id);
            if (!entry) {
                this.createBoard(data);
                added = true;
            } else this.updateBoard(entry, data);
        }
        if (added && this.userMoved === false) this.fit();

        if (this.countLabel) {
            const n = this.state.boards.length;
            this.countLabel.textContent = `${n} artboard${n === 1 ? '' : 's'}`;
        }

        if (this.empty) this.empty.hidden = this.state.boards.length > 0;
        this.loadVisible();
        this.editor?.afterRender();
    }

    renderNotes() {
        // A nota em edição no editor não é recriada (perderia o cursor).
        const editing = this.editor?.editingNote ?? null;
        this.notesLayer.replaceChildren(...this.state.notes.map((note) => {
            if (editing && editing.dataset.note === note.key) return editing;
            const classes = [
                note.kind === 'title' ? 'ward-note-title' : 'ward-note-sticky',
                note.size ? `is-size-${note.size}` : '',
                note.bold ? 'is-bold' : '',
            ].join(' ').trim();
            if (note.kind === 'title') {
                return el('div', {
                    class: classes,
                    text: note.text,
                    'data-note': note.key,
                    style: { left: `${note.x}px`, top: `${note.y}px`, maxWidth: note.w ? `${note.w}px` : '2400px' },
                });
            }
            return el('div', {
                class: classes,
                text: note.text,
                'data-note': note.key,
                style: {
                    left: `${note.x}px`, top: `${note.y}px`, width: `${note.w || 260}px`,
                    background: NOTE_COLORS[note.color] || NOTE_COLORS.yellow,
                },
            });
        }));
    }

    /** Ponto da tela (clientX/Y) em coordenadas do canvas. */
    toWorld(clientX, clientY) {
        const rect = this.viewport.getBoundingClientRect();
        return {
            x: (clientX - rect.left - this.view.x) / this.view.scale,
            y: (clientY - rect.top - this.view.y) / this.view.scale,
        };
    }

    createBoard(data) {
        const frame = el('iframe', {
            title: data.title || data.name,
            sandbox: this.config.sandbox,
            allow: this.config.allow,
            referrerpolicy: 'no-referrer',
            tabindex: '-1',
        });
        const label = el('button', { type: 'button', class: 'ward-board-name', text: data.title || data.name });
        const meta = el('span', { class: 'ward-board-meta' });
        const caption = el('div', { class: 'ward-board-caption' }, [label, meta]);
        const flash = el('span', { class: 'ward-board-flash', text: 'atualizado agora', hidden: true });
        const node = el('div', { class: 'ward-board', 'data-board': data.id }, [caption, frame, flash]);

        label.addEventListener('click', (event) => {
            event.stopPropagation();
            this.openFocus(data.id);
        });

        this.boardsLayer.append(node);
        const entry = { data, node, frame, label, meta, flash, loaded: false };
        this.boards.set(data.id, entry);
        this.updateBoard(entry, data, true);

        if (this.list) {
            entry.listNode = this.createListItem(entry);
            this.list.append(entry.listNode);
        }
    }

    updateBoard(entry, data, initial = false) {
        const changed = !initial && data.revision !== entry.data.revision;
        entry.data = data;

        Object.assign(entry.node.style, {
            left: `${data.x}px`, top: `${data.y}px`, width: `${data.w}px`, height: `${data.h}px`,
        });
        Object.assign(entry.frame.style, { width: `${data.w}px`, height: `${data.h}px` });
        entry.label.textContent = data.title || data.name;
        entry.meta.textContent = `${data.w}×${data.h}${data.interactive ? ' · Play' : ''}`;
        entry.node.classList.toggle('is-selected', this.selected === data.id);

        if (changed && entry.loaded) {
            this.swapFrame(entry, data.url);
            this.flash(entry);
        } else if (changed) {
            this.flash(entry);
        }

        if (entry.listNode) this.updateListItem(entry);

        if (this.focusIndex !== null && this.currentFocusId() === data.id && changed) {
            this.renderFocus();
        }
    }

    /** Carrega a versão nova por baixo e só troca quando ela terminou (sem piscar em branco). */
    swapFrame(entry, url) {
        const next = entry.frame.cloneNode(false);
        next.removeAttribute('src');
        next.classList.add('is-loading');
        next.addEventListener('load', () => {
            next.classList.remove('is-loading');
            entry.frame.remove();
            entry.frame = next;
        }, { once: true });
        next.src = url;
        entry.frame.after(next);
    }

    flash(entry) {
        entry.flash.hidden = false;
        entry.node.classList.add('is-updated');
        clearTimeout(entry.flashTimer);
        entry.flashTimer = setTimeout(() => {
            entry.flash.hidden = true;
            entry.node.classList.remove('is-updated');
        }, 4000);
    }

    // ---------- lista (celular) ----------

    createListItem(entry) {
        const frame = el('iframe', {
            title: entry.data.title || entry.data.name,
            sandbox: this.config.sandbox,
            allow: this.config.allow,
            referrerpolicy: 'no-referrer',
            tabindex: '-1',
            loading: 'lazy',
        });
        const box = el('div', { class: 'ward-list-box' }, [frame]);
        const button = el('button', { type: 'button', class: 'ward-list-open', text: 'Abrir' });
        const title = el('div', { class: 'ward-list-title' });
        const item = el('article', { class: 'ward-list-item' }, [
            el('div', { class: 'ward-list-head' }, [title, button]), box,
        ]);
        button.addEventListener('click', () => this.openFocus(entry.data.id));
        box.addEventListener('click', () => this.openFocus(entry.data.id));
        entry.listFrame = frame;
        entry.listBox = box;
        entry.listTitle = title;
        return item;
    }

    updateListItem(entry) {
        const { data } = entry;
        entry.listTitle.textContent = `${data.title || data.name} · ${data.w}×${data.h}`;
        const width = Math.max(1, (this.list.clientWidth || window.innerWidth) - 32);
        const scale = Math.min(1, width / data.w, this.options.listMaxHeight / data.h);
        Object.assign(entry.listBox.style, { width: `${Math.round(data.w * scale)}px`, height: `${Math.round(data.h * scale)}px` });
        Object.assign(entry.listFrame.style, {
            width: `${data.w}px`, height: `${data.h}px`, transform: `scale(${scale})`,
        });
        if (entry.listFrame.dataset.revision !== String(data.revision)) {
            entry.listFrame.src = data.url;
            entry.listFrame.dataset.revision = String(data.revision);
        }
    }

    isMobile() {
        const mobile = window.innerWidth < this.options.mobileBreakpoint;
        this.root.classList.toggle('is-list', mobile);
        return mobile;
    }

    // ---------- pan / zoom ----------

    applyView() {
        const { x, y, scale } = this.view;
        this.world.style.transform = `translate(${x}px, ${y}px) scale(${scale})`;
        this.world.style.setProperty('--inv', String(1 / scale));
        if (this.zoomLabel) this.zoomLabel.textContent = `${Math.round(scale * 100)}%`;
        this.loadVisible();
        this.editor?.afterView();
        if (this.isMobile()) {
            // No celular o canvas fica oculto: não salva uma visualização sem sentido.
            for (const entry of this.boards.values()) if (entry.listNode) this.updateListItem(entry);
            return;
        }
        // Só guarda a visualização que a pessoa escolheu (não o enquadramento automático).
        if (!this.options.persistView || !this.userMoved) return;
        clearTimeout(this.saveTimer);
        this.saveTimer = setTimeout(() => storage(this.viewKey, this.view), 300);
    }

    zoomAt(factor, clientX, clientY) {
        const rect = this.viewport.getBoundingClientRect();
        const px = (clientX ?? rect.left + rect.width / 2) - rect.left;
        const py = (clientY ?? rect.top + rect.height / 2) - rect.top;
        const next = Math.min(MAX_SCALE, Math.max(MIN_SCALE, this.view.scale * factor));
        const ratio = next / this.view.scale;
        this.view = { scale: next, x: px - (px - this.view.x) * ratio, y: py - (py - this.view.y) * ratio };
        this.applyView();
    }

    setScale(scale) {
        this.zoomAt(scale / this.view.scale);
    }

    bounds() {
        const items = [
            ...this.state.boards.map((b) => [b.x, b.y, b.x + b.w, b.y + b.h]),
            ...this.state.notes.map((n) => [n.x, n.y, n.x + (n.w || 260), n.y + 100]),
        ];
        if (!items.length) return null;
        return items.reduce((acc, [a, b, c, d]) => [
            Math.min(acc[0], a), Math.min(acc[1], b), Math.max(acc[2], c), Math.max(acc[3], d),
        ], [Infinity, Infinity, -Infinity, -Infinity]);
    }

    fit() {
        const box = this.bounds();
        const rect = this.viewport.getBoundingClientRect();
        if (!box || !rect.width) {
            this.view = { x: 40, y: 40, scale: 1 };
            return this.applyView();
        }
        const pad = 64;
        const w = box[2] - box[0];
        const h = box[3] - box[1];
        const scale = Math.min(1, Math.max(MIN_SCALE, Math.min((rect.width - pad * 2) / w, (rect.height - pad * 2) / h)));
        this.view = {
            scale,
            x: (rect.width - w * scale) / 2 - box[0] * scale,
            y: (rect.height - h * scale) / 2 - box[1] * scale,
        };
        this.applyView();
    }

    loadVisible() {
        if (this.isMobile()) return;
        const rect = this.viewport.getBoundingClientRect();
        const { x, y, scale } = this.view;
        for (const entry of this.boards.values()) {
            if (entry.loaded) continue;
            const { data } = entry;
            const left = data.x * scale + x;
            const top = data.y * scale + y;
            const visible = left < rect.width + VISIBLE_MARGIN && left + data.w * scale > -VISIBLE_MARGIN
                && top < rect.height + VISIBLE_MARGIN && top + data.h * scale > -VISIBLE_MARGIN;
            if (visible) {
                entry.frame.src = data.url;
                entry.loaded = true;
            }
        }
    }

    bindCanvas() {
        const pointers = new Map();
        let drag = null;
        let pinch = null;

        this.viewport.addEventListener('pointerdown', (event) => {
            if (this.editor?.pointerDown(event)) return;
            if (event.target.closest('button, a')) return;
            this.viewport.setPointerCapture(event.pointerId);
            pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            if (pointers.size === 1) {
                drag = { x: event.clientX, y: event.clientY, vx: this.view.x, vy: this.view.y, moved: false };
            } else if (pointers.size === 2) {
                const [a, b] = [...pointers.values()];
                pinch = { distance: Math.hypot(a.x - b.x, a.y - b.y), scale: this.view.scale };
                drag = null;
            }
        });

        this.viewport.addEventListener('pointermove', (event) => {
            if (!pointers.has(event.pointerId)) return;
            pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            if (pinch && pointers.size === 2) {
                const [a, b] = [...pointers.values()];
                const distance = Math.hypot(a.x - b.x, a.y - b.y);
                this.zoomAt((pinch.scale * distance / pinch.distance) / this.view.scale, (a.x + b.x) / 2, (a.y + b.y) / 2);
                return;
            }
            if (!drag) return;
            const dx = event.clientX - drag.x;
            const dy = event.clientY - drag.y;
            if (!drag.moved && Math.hypot(dx, dy) < 4) return;
            drag.moved = true;
            this.userMoved = true;
            this.viewport.classList.add('is-panning');
            this.view.x = drag.vx + dx;
            this.view.y = drag.vy + dy;
            this.applyView();
        });

        const end = (event) => {
            if (!pointers.has(event.pointerId)) return;
            pointers.delete(event.pointerId);
            if (pointers.size < 2) pinch = null;
            if (drag && !drag.moved && event.type === 'pointerup') {
                const board = event.target.closest('[data-board]');
                this.select(board ? Number(board.dataset.board) : null);
            }
            if (pointers.size === 0) {
                drag = null;
                this.viewport.classList.remove('is-panning');
            }
        };
        this.viewport.addEventListener('pointerup', end);
        this.viewport.addEventListener('pointercancel', end);

        this.viewport.addEventListener('dblclick', (event) => {
            const board = event.target.closest('[data-board]');
            if (board) this.openFocus(Number(board.dataset.board));
        });

        this.viewport.addEventListener('wheel', (event) => {
            event.preventDefault();
            this.userMoved = true;
            if (event.ctrlKey || event.metaKey) {
                this.zoomAt(Math.exp(-event.deltaY * 0.01), event.clientX, event.clientY);
            } else {
                this.view.x -= event.deltaX;
                this.view.y -= event.deltaY;
                this.applyView();
            }
        }, { passive: false });

        this.root.querySelector('[data-zoom-toolbar]')?.addEventListener('click', (event) => {
            const action = event.target.closest('[data-zoom]')?.dataset.zoom;
            if (action) this.userMoved = action !== 'fit';
            if (action === 'in') this.zoomAt(1.25);
            if (action === 'out') this.zoomAt(0.8);
            if (action === 'reset') this.setScale(1);
            if (action === 'fit') this.fit();
        });
    }

    select(id) {
        const changed = this.selected !== id;
        this.selected = id;
        for (const [boardId, entry] of this.boards) entry.node.classList.toggle('is-selected', boardId === id);
        if (changed) this.options.onSelect?.(this.boards.get(id)?.data ?? null);
        this.editor?.afterSelect(id);
    }

    /** Estado novo vindo de fora (resultado de uma ferramenta no MCP App, ou do editor). */
    setState(state, force = false) {
        if (!state || (!force && state.revision === this.state.revision)) return;
        this.state = state;
        this.renderAll();
    }

    destroy() {
        this.destroyed = true;
        clearTimeout(this.pollTimer);
    }

    // ---------- foco e Play ----------

    ordered() {
        return this.state.boards;
    }

    currentFocusId() {
        return this.ordered()[this.focusIndex]?.id ?? null;
    }

    openFocus(id) {
        const index = this.ordered().findIndex((board) => board.id === id);
        if (index < 0) return;
        this.focusIndex = index;
        this.select(id);
        this.focus.hidden = false;
        document.body.style.overflow = 'hidden';
        this.renderFocus();
    }

    closeFocus() {
        this.focusIndex = null;
        this.focus.hidden = true;
        this.focus.querySelector('[data-focus-stage]').replaceChildren();
        document.body.style.overflow = '';
    }

    stepFocus(delta) {
        const boards = this.ordered();
        if (this.focusIndex === null || !boards.length) return;
        this.focusIndex = (this.focusIndex + delta + boards.length) % boards.length;
        this.select(boards[this.focusIndex].id);
        this.renderFocus();
    }

    renderFocus() {
        const boards = this.ordered();
        const data = boards[this.focusIndex];
        if (!data) return this.closeFocus();

        this.focus.querySelector('[data-focus-title]').textContent = data.title || data.name;
        this.focus.querySelector('[data-focus-meta]').textContent =
            `${this.focusIndex + 1} de ${boards.length} · ${data.w}×${data.h}${data.interactive ? ' · Play' : ''}`;
        this.focus.querySelector('[data-focus-open]').href = data.url;

        const stage = this.focus.querySelector('[data-focus-stage]');
        const frame = el('iframe', {
            src: data.url,
            title: data.title || data.name,
            sandbox: this.config.sandbox,
            allow: this.config.allow,
            referrerpolicy: 'no-referrer',
        });

        if (data.kind === 'page') {
            frame.className = 'ward-focus-page';
            stage.replaceChildren(frame);
            return;
        }

        const rect = stage.getBoundingClientRect();
        const scale = Math.min(1, (rect.width - 48) / data.w, (rect.height - 48) / data.h);
        // zoom (e não transform): o iframe recebe cliques e o texto fica nítido.
        Object.assign(frame.style, { width: `${data.w}px`, height: `${data.h}px`, zoom: String(scale) });
        const box = el('div', { class: 'ward-focus-box' }, [frame]);
        stage.replaceChildren(box);
    }

    bindFocus() {
        this.focus.querySelector('[data-focus-close]').addEventListener('click', () => this.closeFocus());
        this.focus.querySelector('[data-focus-prev]').addEventListener('click', () => this.stepFocus(-1));
        this.focus.querySelector('[data-focus-next]').addEventListener('click', () => this.stepFocus(1));
        window.addEventListener('resize', () => {
            if (this.focusIndex !== null) this.renderFocus();
        });
    }

    bindMessages() {
        window.addEventListener('message', (event) => {
            if (event.origin !== this.config.contentOrigin) return;
            if (event.data?.ward !== 'navigate' || typeof event.data.board !== 'string') return;
            const target = this.ordered().find((board) => board.name === event.data.board);
            if (target) this.openFocus(target.id);
        });
    }

    bindKeyboard() {
        document.addEventListener('keydown', (event) => {
            if (event.defaultPrevented) return;
            if (event.target.closest('input, textarea, select, [contenteditable]')) return;
            if (this.focusIndex !== null) {
                if (event.key === 'Escape') this.closeFocus();
                if (event.key === 'ArrowRight') this.stepFocus(1);
                if (event.key === 'ArrowLeft') this.stepFocus(-1);
                return;
            }
            if (event.metaKey || event.ctrlKey || event.altKey) return;
            if (event.key === '+' || event.key === '=') this.zoomAt(1.25);
            else if (event.key === '-') this.zoomAt(0.8);
            else if (event.key === '0') this.setScale(1);
            else if (event.key === '1') this.fit();
            else if ((event.key === 'Enter' || event.key === 'f') && this.selected) this.openFocus(this.selected);
            else if (event.key === 'Escape') this.select(null);
            else return;
            event.preventDefault();
        });
    }

    // ---------- ao vivo ----------

    schedulePoll() {
        clearTimeout(this.pollTimer);
        if (this.destroyed) return;
        const active = document.visibilityState === 'visible' && (this.options.isActive?.() ?? true);
        const delay = active ? this.options.pollVisible : POLL_HIDDEN;
        this.pollTimer = setTimeout(() => this.poll(), delay);
        if (!this.visibilityBound) {
            this.visibilityBound = true;
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') this.poll();
            });
        }
    }

    async poll() {
        clearTimeout(this.pollTimer);
        if (this.destroyed) return;
        if (this.options.poll) {
            try {
                const state = await this.options.poll(this.state.revision);
                if (state) this.setState(state);
            } catch {
                // a fonte decide se tenta de novo; aqui só segue o ciclo
            }
            return this.schedulePoll();
        }
        try {
            const separator = this.config.liveUrl.includes('?') ? '&' : '?';
            const response = await fetch(`${this.config.liveUrl}${separator}rev=${this.state.revision}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (response.status === 403 || response.status === 404) {
                this.showBanner('Este canvas não está mais disponível para esta sessão. Recarregue a página.');
                return;
            }
            // Durante um arraste ou um envio do editor, o estado do servidor espera.
            if (response.status === 200 && !this.hold) {
                this.state = await response.json();
                this.renderAll();
            }
        } catch {
            // rede instável: tenta de novo no próximo ciclo
        }
        this.schedulePoll();
    }

    showBanner(text) {
        this.banner.textContent = text;
        this.banner.hidden = false;
    }
}

window.WardDesignCanvas = DesignCanvas;

const root = document.querySelector('[data-design-canvas]');
const configNode = document.getElementById('ward-design-config');
if (root && configNode) {
    window.wardDesign = new DesignCanvas(root, JSON.parse(configNode.textContent));
}
