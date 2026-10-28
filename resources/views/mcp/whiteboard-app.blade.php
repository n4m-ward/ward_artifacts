<x-mcp::app title="Ward Whiteboard">
    <x-slot:head>
        {{-- O mesmo pacote da página do quadro (Excalidraw), servido pelo app com CORS. --}}
        @vite(['resources/js/whiteboard/main.jsx'])
        <style>
            :root { color-scheme: light; --wa-bg: #ffffff; --wa-fg: #18181b; --wa-muted: #71717a; --wa-line: rgb(127 127 127 / .25); --wa-chip: #f4f4f5; }
            :root.dark { color-scheme: dark; --wa-bg: #1c1c1c; --wa-fg: #f4f4f5; --wa-muted: #a1a1aa; --wa-line: rgb(255 255 255 / .12); --wa-chip: #27272a; }
            html, body { margin: 0; background: var(--wa-bg); color: var(--wa-fg); font: 14px/1.4 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
            body { height: 560px; }
            html[data-display="fullscreen"] body, html[data-fill="1"] body { height: 100vh; }
            #ward-app { display: flex; flex-direction: column; height: 100%; }
            .wa-bar { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1px solid var(--wa-line); }
            .wa-logo { flex: none; width: 22px; height: 22px; }
            .wa-heading { min-width: 0; flex: 1; line-height: 1.2; }
            .wa-heading strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px; }
            .wa-heading span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; color: var(--wa-muted); }
            .wa-btn { display: inline-flex; align-items: center; min-height: 30px; padding: 0 10px; border-radius: 8px; border: 1px solid var(--wa-line);
                background: var(--wa-bg); color: inherit; font: inherit; font-size: 12px; cursor: pointer; white-space: nowrap; }
            .wa-btn:hover { background: var(--wa-chip); }
            .wa-btn[hidden] { display: none; }
            .wa-board { position: relative; flex: 1; min-height: 0; background: #fff; color: #1e1e1e; }
            .wa-ask { display: flex; gap: 8px; align-items: center; padding: 8px 10px; border-top: 1px solid var(--wa-line); }
            .wa-ask input { flex: 1; min-width: 0; min-height: 32px; padding: 0 10px; border-radius: 8px; border: 1px solid var(--wa-line); background: var(--wa-bg); color: inherit; font: inherit; }
            .wa-ask small { color: var(--wa-muted); font-size: 12px; white-space: nowrap; }
            .wa-error { margin: 12px; padding: 10px 12px; border-radius: 8px; background: #fee2e2; color: #7f1d1d; }
            .wa-error[hidden] { display: none; }
            .wa-empty { display: flex; height: 100%; align-items: center; justify-content: center; color: var(--wa-muted); }
        </style>
    </x-slot:head>

    <div id="ward-app">
        <header class="wa-bar">
            <svg class="wa-logo" viewBox="129 103 774 855" aria-hidden="true"><path fill="currentColor" d="M516 103L129 352.5L129 487.5L216 433L216 401.5L463.5 246.5L463.5 802.5L354 708L354 419L129 560.5L129 611L216 684L216 611L268.5 577L268.5 745.5L516 958L763.5 745.5L763.5 577L816 611L816 684L903 611L903 560.5L678 419L678 708L568.5 802.5L568.5 246.5L816 401.5L816 433L903 487.5L903 352.5Z"/></svg>
            <div class="wa-heading">
                <strong data-title>Ward Whiteboard</strong>
                <span data-sub>Carregando…</span>
            </div>
            <button type="button" class="wa-btn" data-action="fullscreen" hidden>Tela cheia</button>
            <button type="button" class="wa-btn" data-action="open" hidden>Abrir para editar ↗</button>
        </header>
        <p class="wa-error" data-error hidden role="alert"></p>
        <main class="wa-board" data-board><div class="wa-empty">Abrindo o quadro…</div></main>
        <form class="wa-ask" data-ask hidden>
            <input type="text" data-ask-input placeholder="Pedir mudança no quadro (vai para o chat)" aria-label="Pedir mudança no quadro">
            <button type="submit" class="wa-btn">Enviar</button>
            <small data-ask-status aria-live="polite"></small>
        </form>
    </div>

    <script type="module">
        const $ = (selector) => document.querySelector(selector);
        const ui = {
            title: $('[data-title]'), sub: $('[data-sub]'), board: $('[data-board]'), error: $('[data-error]'),
            fullscreen: $('[data-action="fullscreen"]'), open: $('[data-action="open"]'),
            ask: $('[data-ask]'), askInput: $('[data-ask-input]'), askStatus: $('[data-ask-status]'),
        };
        const textOf = (result) => (result?.content ?? []).filter((c) => c.type === 'text').map((c) => c.text).join('\n');

        createMcpApp(async (app) => {
            let config = null;
            let unmount = null;

            function applyContext(context) {
                if (!context) return;
                if (context.theme) document.documentElement.classList.toggle('dark', context.theme === 'dark');
                if (context.containerDimensions) document.documentElement.dataset.fill = context.containerDimensions.height ? '1' : '';
                const display = context.displayMode || document.documentElement.dataset.display || 'inline';
                document.documentElement.dataset.display = display;
                if (context.availableDisplayModes) ui.fullscreen.hidden = !context.availableDisplayModes.includes('fullscreen');
                ui.fullscreen.textContent = display === 'fullscreen' ? 'Sair da tela cheia' : 'Tela cheia';
            }

            function showError(message) {
                ui.error.textContent = message || 'Algo deu errado.';
                ui.error.hidden = false;
                ui.sub.textContent = 'Erro';
            }

            // Ao vivo pela conexão do app quando o host bloqueia o fetch direto (ou o link venceu).
            async function fallbackPoll(since, presence) {
                const result = await app.callServerTool('whiteboard_live', { whiteboard: config.board, since, p: presence });
                if (result?.isError) throw new Error(textOf(result));
                const content = result?.structuredContent ?? {};
                return content.changed || content.unchanged ? content : null;
            }

            async function loadInitial() {
                try {
                    const url = new URL(config.liveUrl);
                    url.searchParams.set('since', '0');
                    const response = await fetch(url, { credentials: 'omit', cache: 'no-store', headers: { Accept: 'application/json' } });
                    if (response.ok) return await response.json();
                } catch {
                    // CSP do cliente: cai para a ferramenta
                }
                const fresh = await fallbackPoll(0, '');
                if (!fresh) throw new Error('Não foi possível carregar o quadro.');
                return fresh;
            }

            function enter() {
                ui.title.textContent = config.title;
                ui.sub.textContent = 'Ao vivo · só leitura aqui; edite pelo link';
                ui.open.hidden = false;
                ui.ask.hidden = false;
                unmount?.();
                if (!window.WardWhiteboard) return showError('Não foi possível carregar o quadro (o pacote do Ward não abriu neste chat). Use o link para abrir.');
                unmount = window.WardWhiteboard.mount(ui.board, config, { app: true, fallbackPoll });
            }

            applyContext(app.getHostContext());
            app.onHostContextChanged(applyContext);
            app.onToolInput(() => { ui.sub.textContent = 'Abrindo o quadro…'; });

            app.onToolResult(async (result) => {
                if (result?.isError) return showError(textOf(result));
                const content = result?.structuredContent ?? {};
                if (!content.app) return showError('Resposta inesperada da ferramenta.');
                config = content.app;
                // O open_whiteboard não traz o quadro (para não encher o contexto da IA): busca
                // pela URL assinada e, se o host bloquear ou o link tiver vencido, pela conexão do app.
                if (!config.initial || Date.now() - (config.issuedAt || 0) > 10 * 60 * 1000) {
                    try {
                        config = { ...config, initial: await loadInitial() };
                    } catch (error) {
                        return showError(error.message);
                    }
                }
                enter();
            });

            ui.open.addEventListener('click', () => config && app.openLink({ url: config.url }));
            ui.fullscreen.addEventListener('click', async () => {
                const next = document.documentElement.dataset.display === 'fullscreen' ? 'inline' : 'fullscreen';
                try {
                    const result = await app.requestDisplayMode({ mode: next });
                    applyContext({ displayMode: result?.mode ?? next });
                } catch {
                    // o host recusou: continua como está
                }
            });

            ui.ask.addEventListener('submit', async (event) => {
                event.preventDefault();
                const text = ui.askInput.value.trim();
                if (!text || !config) return;
                ui.askStatus.textContent = 'Enviando…';
                try {
                    await app.sendMessage({ role: 'user', content: [{ type: 'text', text: `[Ward Whiteboard · "${config.title}" (${config.board})] ${text}` }] });
                    ui.askInput.value = '';
                    ui.askStatus.textContent = 'Enviado';
                } catch {
                    ui.askStatus.textContent = 'Não foi possível enviar';
                }
                setTimeout(() => { ui.askStatus.textContent = ''; }, 3000);
            });

            app.autoResize?.();
        });
    </script>
</x-mcp::app>
