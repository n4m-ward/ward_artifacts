<x-mcp::app title="Ward Design">
    <x-slot:head>
        @include('designs.canvas-styles')
        <style>
            :root { color-scheme: light; --wa-bg: #ffffff; --wa-fg: #18181b; --wa-muted: #71717a; --wa-line: rgb(127 127 127 / .25); --wa-chip: #f4f4f5; }
            :root.dark { color-scheme: dark; --wa-bg: #1c1c1c; --wa-fg: #f4f4f5; --wa-muted: #a1a1aa; --wa-line: rgb(255 255 255 / .12); --wa-chip: #27272a; }
            html, body { margin: 0; background: var(--wa-bg); color: var(--wa-fg); font: 14px/1.4 var(--font-sans, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif); }
            html[data-mode="canvas"] body { height: 560px; }
            html[data-display="fullscreen"] body { height: 100vh; }
            #ward-app { display: flex; flex-direction: column; height: 100%; min-height: 120px; }
            .wa-bar { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1px solid var(--wa-line); }
            .wa-logo { flex: none; width: 22px; height: 22px; }
            .wa-heading { min-width: 0; flex: 1; line-height: 1.2; }
            .wa-heading strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px; }
            .wa-heading span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; color: var(--wa-muted); }
            .wa-btn { display: inline-flex; align-items: center; gap: 6px; min-height: 30px; padding: 0 10px; border-radius: 8px; border: 1px solid var(--wa-line);
                background: var(--wa-bg); color: inherit; font: inherit; font-size: 12px; cursor: pointer; white-space: nowrap; }
            .wa-btn:hover { background: var(--wa-chip); }
            .wa-btn:focus-visible { outline: 2px solid #38bdf8; outline-offset: 1px; }
            .wa-btn[hidden] { display: none; }
            .wa-stream { padding: 12px; }
            .wa-stream-stage { position: relative; margin: 0 auto; overflow: hidden; border-radius: 6px; background: #fff; box-shadow: 0 0 0 1px var(--wa-line); }
            .wa-stream-stage iframe { position: absolute; left: 0; top: 0; border: 0; transform-origin: 0 0; background: #fff; }
            .wa-stream-stage .is-next { visibility: hidden; }
            .wa-writing { position: absolute; z-index: 1; right: 8px; top: 8px; padding: 2px 8px; border-radius: 999px; background: #38bdf8; color: #082f49; font-size: 11px; font-weight: 600; }
            .wa-canvas { position: relative; flex: 1; min-height: 0; }
            .wa-pill { pointer-events: none; position: absolute; left: 10px; top: 10px; display: flex; align-items: center; gap: 6px; padding: 3px 10px;
                border-radius: 999px; border: 1px solid var(--wa-line); background: color-mix(in srgb, var(--wa-bg) 90%, transparent); font-size: 12px; color: var(--wa-muted); }
            .wa-dot { width: 7px; height: 7px; border-radius: 999px; background: #10b981; }
            .wa-banner { position: absolute; left: 50%; top: 10px; transform: translateX(-50%); padding: 6px 10px; border-radius: 8px; background: #fef3c7; color: #78350f; font-size: 12px; }
            .wa-empty { pointer-events: none; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; padding: 24px; text-align: center; color: var(--wa-muted); }
            .wa-empty[hidden], .wa-banner[hidden] { display: none; }
            .ward-zoom { position: absolute; right: 10px; bottom: 10px; display: flex; gap: 2px; padding: 3px; border-radius: 8px; border: 1px solid var(--wa-line); background: var(--wa-bg); }
            .ward-zoom button { min-width: 30px; min-height: 28px; border: 0; border-radius: 6px; background: none; color: inherit; font: inherit; font-size: 12px; cursor: pointer; }
            .ward-zoom button:hover { background: var(--wa-chip); }
            .wa-ask { display: flex; gap: 8px; align-items: center; padding: 8px 10px; border-top: 1px solid var(--wa-line); }
            .wa-ask[hidden] { display: none; }
            .wa-ask input { flex: 1; min-width: 0; min-height: 32px; padding: 0 10px; border-radius: 8px; border: 1px solid var(--wa-line); background: var(--wa-bg); color: inherit; font: inherit; }
            .wa-ask input:focus { outline: 2px solid #38bdf8; outline-offset: 0; }
            .wa-ask small { color: var(--wa-muted); font-size: 12px; white-space: nowrap; }
            .wa-error { margin: 12px; padding: 10px 12px; border-radius: 8px; background: #fee2e2; color: #7f1d1d; }
            .wa-error[hidden], .wa-stream[hidden], .wa-canvas[hidden] { display: none; }
            .wa-focus { position: fixed; inset: 0; z-index: 50; display: flex; flex-direction: column; background: #0b0b0b; color: #f4f4f5; }
            .wa-focus[hidden] { display: none; }
            .wa-focus-bar { display: flex; align-items: center; gap: 6px; height: 44px; padding: 0 10px; border-bottom: 1px solid rgb(255 255 255 / .1); }
            .wa-focus-bar .wa-btn { background: transparent; color: #f4f4f5; border-color: rgb(255 255 255 / .15); text-decoration: none; }
            .wa-focus-bar .wa-btn:hover { background: rgb(255 255 255 / .08); }
            .wa-focus-stage { position: relative; flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        </style>
    </x-slot:head>

    <div id="ward-app">
        <header class="wa-bar">
            <svg class="wa-logo" viewBox="129 103 774 855" aria-hidden="true"><path fill="currentColor" d="M516 103L129 352.5L129 487.5L216 433L216 401.5L463.5 246.5L463.5 802.5L354 708L354 419L129 560.5L129 611L216 684L216 611L268.5 577L268.5 745.5L516 958L763.5 745.5L763.5 577L816 611L816 684L903 611L903 560.5L678 419L678 708L568.5 802.5L568.5 246.5L816 401.5L816 433L903 487.5L903 352.5Z"/></svg>
            <div class="wa-heading">
                <strong data-title>Ward Design</strong>
                <span data-sub>Carregando…</span>
            </div>
            <button type="button" class="wa-btn" data-action="canvas" hidden>Ver canvas</button>
            <button type="button" class="wa-btn" data-action="fullscreen" hidden>Tela cheia</button>
            <button type="button" class="wa-btn" data-action="open" hidden>Abrir ↗</button>
        </header>

        <section class="wa-stream" data-stream hidden>
            <div class="wa-stream-stage" data-stream-stage></div>
        </section>

        <main class="wa-canvas" data-design-canvas hidden>
            <div class="ward-viewport" data-canvas-viewport>
                <div class="ward-world" data-canvas-world>
                    <div data-canvas-notes></div>
                    <div data-canvas-boards></div>
                </div>
            </div>
            <div class="ward-list" data-canvas-list aria-label="Artboards"></div>
            <p class="wa-empty" data-empty hidden>Nenhum artboard ainda. Eles aparecem aqui assim que a IA criar.</p>
            <div class="wa-pill"><span class="wa-dot" aria-hidden="true"></span><span>Ao vivo</span><span aria-hidden="true">·</span><span data-board-count></span></div>
            <p class="wa-banner" data-banner hidden role="status"></p>
            <div class="ward-zoom" role="toolbar" aria-label="Zoom" data-zoom-toolbar>
                <button type="button" data-zoom="out" aria-label="Diminuir zoom">−</button>
                <button type="button" data-zoom="reset" aria-label="Zoom 100%"><span data-zoom-label>100%</span></button>
                <button type="button" data-zoom="in" aria-label="Aumentar zoom">+</button>
                <button type="button" data-zoom="fit" aria-label="Ajustar tudo na tela">⤢</button>
            </div>
        </main>

        <form class="wa-ask" data-ask hidden>
            <input type="text" name="ask" autocomplete="off" maxlength="2000" data-ask-input placeholder="Pedir uma mudança no design…" aria-label="Pedir uma mudança">
            <button type="submit" class="wa-btn">Enviar</button>
            <small data-ask-status role="status"></small>
        </form>

        <p class="wa-error" data-error hidden role="alert"></p>
    </div>

    <div class="wa-focus" data-focus hidden role="dialog" aria-modal="true" aria-label="Artboard em foco">
        <div class="wa-focus-bar">
            <div class="wa-heading">
                <strong data-focus-title></strong>
                <span data-focus-meta></span>
            </div>
            <button type="button" class="wa-btn" data-focus-prev aria-label="Artboard anterior">‹</button>
            <button type="button" class="wa-btn" data-focus-next aria-label="Próximo artboard">›</button>
            <a class="wa-btn" data-focus-open href="#" target="_blank" rel="noopener noreferrer" aria-label="Abrir sozinho">↗</a>
            <button type="button" class="wa-btn" data-focus-close aria-label="Fechar (Esc)">✕</button>
        </div>
        <div class="wa-focus-stage" data-focus-stage></div>
    </div>

    <script type="module">{!! $canvasScript !!}</script>
    <script type="module">
        const $ = (selector) => document.querySelector(selector);
        const ui = {
            title: $('[data-title]'), sub: $('[data-sub]'), stream: $('[data-stream]'), stage: $('[data-stream-stage]'),
            canvas: $('[data-design-canvas]'), ask: $('[data-ask]'), askInput: $('[data-ask-input]'), askStatus: $('[data-ask-status]'),
            error: $('[data-error]'), toCanvas: $('[data-action="canvas"]'), fullscreen: $('[data-action="fullscreen"]'), open: $('[data-action="open"]'),
        };
        const STALE_MS = 10 * 60 * 1000; // estado mais velho que isso (chat reaberto) é buscado de novo
        const STREAM_MAX_H = 520;

        const textOf = (result) => (result?.content ?? []).filter((c) => c.type === 'text').map((c) => c.text).join('\n');
        const boardLabel = (board) => board.title || board.name;

        createMcpApp(async (app) => {
            let toolName = null;
            let config = null;
            let canvas = null;
            let selected = null;
            let visible = true;
            let fetchFailures = 0;
            let lastArgs = {};
            let stream = { timer: null, pending: null, size: null };
            let reportTimer = null;

            new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; }).observe(document.body);

            function applyContext(context) {
                if (!context) return;
                if (context.theme) document.documentElement.classList.toggle('dark', context.theme === 'dark');
                if (context.toolInfo?.tool?.name) toolName = context.toolInfo.tool.name;
                const display = context.displayMode || document.documentElement.dataset.display || 'inline';
                document.documentElement.dataset.display = display;
                ui.fullscreen.hidden = !(context.availableDisplayModes || []).includes('fullscreen');
                ui.fullscreen.textContent = display === 'fullscreen' ? 'Sair da tela cheia' : 'Tela cheia';
                if (canvas) requestAnimationFrame(() => canvas.fit());
            }

            function showError(message) {
                ui.error.textContent = message || 'Algo deu errado.';
                ui.error.hidden = false;
                ui.sub.textContent = 'Erro';
            }

            function isUpsert(args) {
                return toolName ? toolName === 'upsert_artboard' : typeof args?.name === 'string';
            }

            // ---------- estado do design ----------

            async function loadConfig(design) {
                const result = await app.callServerTool('design_live', { design });
                if (result?.isError) throw new Error(textOf(result));
                return result.structuredContent.app;
            }

            async function poll(revision) {
                if (config.liveUrl && fetchFailures < 2) {
                    try {
                        const separator = config.liveUrl.includes('?') ? '&' : '?';
                        const response = await fetch(`${config.liveUrl}${separator}rev=${revision}`, {
                            credentials: 'omit', cache: 'no-store', headers: { Accept: 'application/json' },
                        });
                        if (response.status === 204) { fetchFailures = 0; return null; }
                        if (response.ok) { fetchFailures = 0; return await response.json(); }
                    } catch {
                        // CSP do cliente ou assinatura vencida: cai para a ferramenta
                    }
                    fetchFailures++;
                    if (fetchFailures >= 2 && canvas) canvas.options.pollVisible = 4000;
                }
                const result = await app.callServerTool('design_live', { design: config.design, rev: revision });
                const content = result?.structuredContent;
                if (!content?.changed) return null;
                config = { ...config, ...content.app };
                return content.app.state;
            }

            function setHeading() {
                ui.title.textContent = config.title;
                ui.sub.textContent = 'Canvas ao vivo';
                ui.open.hidden = false;
            }

            // ---------- canvas ----------

            function enterCanvas(focusId = null) {
                document.documentElement.dataset.mode = 'canvas';
                ui.stream.hidden = true;
                ui.canvas.hidden = false;
                ui.toCanvas.hidden = true;
                ui.ask.hidden = false;
                setHeading();
                if (!canvas) {
                    canvas = new window.WardDesignCanvas(ui.canvas, config, {
                        persistView: false,
                        pollVisible: 2000,
                        mobileBreakpoint: 440,
                        listMaxHeight: 420,
                        isActive: () => visible,
                        poll,
                        onSelect,
                    });
                } else {
                    canvas.setState(config.state);
                }
                if (focusId !== null) canvas.openFocus(focusId);
                app.autoResize?.();
            }

            function onSelect(board) {
                selected = board;
                ui.askInput.placeholder = board ? `Pedir uma mudança em “${boardLabel(board)}”…` : 'Pedir uma mudança no design…';
                clearTimeout(reportTimer);
                reportTimer = setTimeout(() => {
                    const where = `no canvas do design "${config.title}" (id ${config.design})`;
                    app.updateModelContext({
                        content: [{ type: 'text', text: board
                            ? `A pessoa selecionou o artboard "${board.name}" (${board.w}×${board.h}) ${where}.`
                            : `A pessoa não tem artboard selecionado ${where}.` }],
                    }).catch(() => {});
                    app.callServerTool('design_report_view', { design: config.design, board: board?.name ?? '' }).catch(() => {});
                }, 400);
            }

            // ---------- prévia do artboard (upsert_artboard) ----------

            function streamSize(args) {
                const known = config?.state.boards.find((board) => board.name === args.name);
                const w = Number(args.w) || known?.w || 390;
                const h = Number(args.h) || known?.h || 844;
                return { w, h };
            }

            function layoutStage(w, h) {
                const available = Math.max(120, ui.stream.clientWidth - 24);
                const scale = Math.min(1, available / w, STREAM_MAX_H / h);
                Object.assign(ui.stage.style, { width: `${Math.round(w * scale)}px`, height: `${Math.round(h * scale)}px` });
                for (const frame of ui.stage.querySelectorAll('iframe')) {
                    Object.assign(frame.style, { width: `${w}px`, height: `${h}px`, transform: `scale(${scale})` });
                }
                stream.size = { w, h };
            }

            function showStream(args, writing) {
                lastArgs = { ...lastArgs, ...args };
                document.documentElement.dataset.mode = 'stream';
                ui.stream.hidden = false;
                ui.canvas.hidden = true;
                ui.title.textContent = lastArgs.title || lastArgs.name || 'Artboard';
                ui.sub.textContent = writing ? 'A IA está escrevendo…' : 'Salvando…';
                if (typeof lastArgs.html !== 'string' || !lastArgs.html) return;
                stream.pending = lastArgs.html;
                if (!stream.timer) renderStream();
            }

            // Duas camadas: a nova só aparece quando terminou de carregar (sem piscar).
            function renderStream() {
                const html = stream.pending;
                stream.pending = null;
                stream.timer = setTimeout(() => {
                    stream.timer = null;
                    if (stream.pending !== null) renderStream();
                }, 350);
                const frame = document.createElement('iframe');
                frame.setAttribute('sandbox', '');
                frame.setAttribute('title', 'Prévia do artboard');
                frame.className = 'is-next';
                frame.addEventListener('load', () => {
                    frame.classList.remove('is-next');
                    for (const old of ui.stage.querySelectorAll('iframe')) if (old !== frame) old.remove();
                }, { once: true });
                frame.srcdoc = html;
                ui.stage.append(frame);
                const { w, h } = streamSize(lastArgs);
                layoutStage(w, h);
                if (!ui.stage.querySelector('.wa-writing')) {
                    const badge = document.createElement('span');
                    badge.className = 'wa-writing';
                    badge.textContent = 'escrevendo…';
                    ui.stage.append(badge);
                }
            }

            function showSavedBoard(board) {
                clearTimeout(stream.timer);
                stream = { timer: null, pending: null, size: null };
                ui.stream.hidden = false;
                ui.canvas.hidden = true;
                const frame = document.createElement('iframe');
                frame.src = board.url;
                frame.title = boardLabel(board);
                frame.setAttribute('sandbox', config.sandbox);
                frame.setAttribute('allow', config.allow);
                frame.setAttribute('referrerpolicy', 'no-referrer');
                ui.stage.replaceChildren(frame);
                layoutStage(board.w, board.h);
                ui.title.textContent = boardLabel(board);
                ui.sub.textContent = `${config.title} · ${board.w}×${board.h} · revisão ${board.revision}`;
                ui.toCanvas.hidden = false;
                ui.open.hidden = false;
            }

            // ---------- eventos do host ----------

            applyContext(app.getHostContext());
            app.onHostContextChanged(applyContext);

            app.onToolInputPartial((params) => {
                const args = params?.arguments ?? {};
                if (isUpsert(args)) showStream(args, true);
            });

            app.onToolInput((params) => {
                const args = params?.arguments ?? {};
                if (isUpsert(args)) showStream(args, false);
                else ui.sub.textContent = 'Abrindo o canvas…';
            });

            app.onToolResult(async (result) => {
                if (result?.isError) return showError(textOf(result));
                const content = result?.structuredContent ?? {};
                try {
                    if (content.app) {
                        config = content.app;
                        if (!config.issuedAt || Date.now() - config.issuedAt > STALE_MS) config = await loadConfig(config.design);
                        return enterCanvas();
                    }
                    if (content.design && content.board) {
                        config = await loadConfig(content.design);
                        const board = config.state.boards.find((item) => item.name === content.board.name);
                        return board ? showSavedBoard(board) : enterCanvas();
                    }
                    showError('Resposta inesperada da ferramenta.');
                } catch (error) {
                    showError(error.message);
                }
            });

            app.onToolCancelled?.(() => { ui.sub.textContent = 'Cancelado'; });

            // ---------- ações ----------

            ui.toCanvas.addEventListener('click', () => enterCanvas());
            ui.open.addEventListener('click', () => config && app.openLink({ url: config.url }));
            ui.fullscreen.addEventListener('click', async () => {
                const next = document.documentElement.dataset.display === 'fullscreen' ? 'inline' : 'fullscreen';
                try {
                    const result = await app.requestDisplayMode({ mode: next });
                    applyContext({ displayMode: result?.mode ?? next, availableDisplayModes: ['inline', 'fullscreen'] });
                } catch {
                    // o host recusou: continua como está
                }
            });

            // Links abrem pelo host (o iframe do app não abre abas sozinho).
            document.addEventListener('click', (event) => {
                const link = event.target.closest('a[data-focus-open]');
                if (!link) return;
                event.preventDefault();
                app.openLink({ url: link.href }).catch(() => {});
            }, true);

            // Play na prévia: um link board: abre o canvas já no artboard de destino.
            window.addEventListener('message', (event) => {
                if (canvas || !config || event.origin !== config.contentOrigin) return;
                if (event.data?.ward !== 'navigate' || typeof event.data.board !== 'string') return;
                const target = config.state.boards.find((board) => board.name === event.data.board);
                if (target) enterCanvas(target.id);
            });

            ui.ask.addEventListener('submit', async (event) => {
                event.preventDefault();
                const text = ui.askInput.value.trim();
                if (!text || !config) return;
                const where = selected ? ` · artboard "${selected.name}"` : '';
                ui.askStatus.textContent = 'Enviando…';
                try {
                    await app.sendMessage({ role: 'user', content: [{ type: 'text', text: `[Ward Design · "${config.title}" (${config.design})${where}] ${text}` }] });
                    ui.askInput.value = '';
                    ui.askStatus.textContent = 'Enviado';
                } catch {
                    ui.askStatus.textContent = 'Não foi possível enviar';
                }
                setTimeout(() => { ui.askStatus.textContent = ''; }, 3000);
            });

            window.addEventListener('resize', () => {
                if (!ui.stream.hidden && stream.size) layoutStage(stream.size.w, stream.size.h);
                else if (!ui.stream.hidden && lastArgs.name) layoutStage(...Object.values(streamSize(lastArgs)));
            });

            app.autoResize?.();
        });
    </script>
</x-mcp::app>
