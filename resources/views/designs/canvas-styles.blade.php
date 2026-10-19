{{-- CSS do canvas do Ward Design: página do design e MCP App (sem Tailwind). --}}
<style>
    .ward-viewport { position: absolute; inset: 0; overflow: hidden; touch-action: none; cursor: grab;
        background-color: #f4f4f5; background-image: radial-gradient(circle, rgb(120 120 120 / .28) 1px, transparent 1px); background-size: 24px 24px; }
    .dark .ward-viewport { background-color: #111111; background-image: radial-gradient(circle, rgb(160 160 160 / .16) 1px, transparent 1px); }
    .ward-viewport.is-panning { cursor: grabbing; }
    .ward-world { position: absolute; left: 0; top: 0; transform-origin: 0 0; --inv: 1; }
    .ward-board { position: absolute; }
    .ward-board iframe.is-loading { position: absolute; left: 0; top: 0; visibility: hidden; }
    .ward-board iframe { display: block; border: 0; background: #fff; pointer-events: none;
        box-shadow: 0 1px 3px rgb(0 0 0 / .18), 0 0 0 1px rgb(0 0 0 / .08); }
    .dark .ward-board iframe { box-shadow: 0 1px 3px rgb(0 0 0 / .5), 0 0 0 1px rgb(255 255 255 / .08); }
    .ward-board.is-selected iframe { box-shadow: 0 0 0 calc(2px * var(--inv)) #38bdf8; }
    .ward-board.is-updated iframe { box-shadow: 0 0 0 calc(3px * var(--inv)) #f59e0b; transition: box-shadow .3s; }
    .ward-board-caption { position: absolute; left: 0; bottom: 100%; display: flex; align-items: baseline; gap: 8px;
        padding-bottom: 8px; white-space: nowrap; transform: scale(var(--inv)); transform-origin: 0 100%; font-size: 13px;
        max-width: calc(100% / var(--inv)); overflow: hidden; }
    .ward-board-name { flex: 0 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; font: inherit; font-weight: 600; color: #3f3f46; background: none; border: 0; padding: 0; cursor: pointer; }
    .dark .ward-board-name { color: #e4e4e7; }
    .ward-board-name:hover, .ward-board-name:focus-visible { color: #0284c7; text-decoration: underline; }
    .ward-board-meta { flex: 0 100 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; color: #71717a; }
    .ward-board-flash { position: absolute; top: 0; right: 0; transform: scale(var(--inv)); transform-origin: 100% 0; pointer-events: none;
        margin: 8px; padding: 2px 8px; box-shadow: 0 1px 3px rgb(0 0 0 / .3); border-radius: 999px; background: #f59e0b; color: #1c1917; font-size: 12px; font-weight: 600; }
    .ward-note-title { position: absolute; width: max-content; font-weight: 700; font-size: 72px; line-height: 1.05; letter-spacing: -.02em; color: #27272a; white-space: pre-line; }
    .dark .ward-note-title { color: #f4f4f5; }
    .ward-note-sticky { position: absolute; padding: 16px; border-radius: 8px; font-size: 18px; line-height: 1.35; color: #18181b;
        white-space: pre-line; box-shadow: 0 1px 2px rgb(0 0 0 / .12); }
    /* Play: a tela no tamanho dela, numa moldura de aparelho, com histórico e telas por cima. */
    .ward-board-play { flex: none; align-self: center; width: 22px; height: 22px; padding: 0; border: 0; border-radius: 999px; background: #18181b; color: #fff;
        font-size: 9px; line-height: 22px; text-align: center; cursor: pointer; opacity: .55; }
    .dark .ward-board-play { background: #f4f4f5; color: #18181b; }
    .ward-board:hover .ward-board-play, .ward-board.is-selected .ward-board-play, .ward-board-play:focus-visible { opacity: 1; }
    .ward-board-play:hover { background: #0284c7; color: #fff; }
    .ward-play-start, .ward-links-toggle { height: 34px; padding: 0 12px; border-radius: 9px; border: 1px solid #e4e4e7; background: rgb(255 255 255 / .95); color: #18181b;
        font: 600 13px system-ui, sans-serif; cursor: pointer; white-space: nowrap; }
    .ward-play-start { border-color: #18181b; background: #18181b; color: #fff; }
    .dark .ward-links-toggle { border-color: #3f3f46; background: rgb(24 24 27 / .95); color: #f4f4f5; }
    .dark .ward-play-start { border-color: #f4f4f5; background: #f4f4f5; color: #18181b; }
    .ward-links-toggle[aria-pressed="true"] { border-color: #2563eb; background: #2563eb; color: #fff; }
    .ward-play-btn { display: inline-flex; align-items: center; min-height: 30px; padding: 0 10px; border-radius: 8px; border: 1px solid rgb(255 255 255 / .15);
        background: transparent; color: #f4f4f5; font: 500 13px system-ui, sans-serif; cursor: pointer; white-space: nowrap; }
    .ward-play-btn:hover:not(:disabled) { background: rgb(255 255 255 / .08); }
    .ward-play-btn:disabled { opacity: .4; cursor: default; }
    .ward-play-btn[aria-pressed="true"] { border-color: #60a5fa; background: rgb(37 99 235 / .35); }
    @media (max-width: 760px) { .ward-play-btn[title^="Mostrar a moldura"], .ward-play-btn[title^="Tamanho real"] { display: none; } }
    [data-focus-stage].is-real { display: block !important; overflow: auto !important; }
    [data-focus-stage].is-real .ward-device { margin: 32px auto; }
    .ward-device { position: relative; flex: none; width: max-content; box-sizing: content-box; }
    .ward-device.is-phone { padding: 12px; border-radius: 52px; background: #0a0a0a; box-shadow: 0 0 0 2px #3f3f46, 0 30px 80px rgb(0 0 0 / .55); }
    .ward-device.is-phone .ward-play-screen { border-radius: 40px; }
    .ward-device.is-tablet { padding: 16px; border-radius: 36px; background: #0a0a0a; box-shadow: 0 0 0 2px #3f3f46, 0 30px 80px rgb(0 0 0 / .55); }
    .ward-device.is-tablet .ward-play-screen { border-radius: 20px; }
    .ward-device.is-browser { border-radius: 10px; overflow: hidden; background: #27272a; box-shadow: 0 0 0 1px #3f3f46, 0 30px 80px rgb(0 0 0 / .55); }
    .ward-device-bar { display: flex; align-items: center; gap: 6px; height: 36px; padding: 0 12px; font: 12px system-ui, sans-serif; color: #a1a1aa; }
    .ward-device-bar span { width: 11px; height: 11px; border-radius: 999px; background: #52525b; }
    .ward-device-bar em { flex: 1; overflow: hidden; margin: 0 40px 0 12px; padding: 4px 10px; border-radius: 6px; background: #18181b; font-style: normal;
        text-align: center; white-space: nowrap; text-overflow: ellipsis; }
    .ward-device.is-none { box-shadow: 0 10px 40px rgb(0 0 0 / .4); }
    .ward-play-screen { position: relative; overflow: hidden; background: #fff; }
    .ward-play-screen.is-transparent { background: transparent; outline: 1px dashed rgb(255 255 255 / .35); }
    .ward-play-layer { position: absolute; inset: 0; background: inherit; }
    .ward-play-layer.is-entering { visibility: hidden; }
    .ward-play-layer iframe, .ward-play-panel iframe { display: block; border: 0; background: #fff; color-scheme: normal; }
    .ward-play-screen.is-transparent iframe { background: transparent; }
    .ward-play-overlay { position: absolute; inset: 0; z-index: 2; display: flex; align-items: center; justify-content: center; background: rgb(0 0 0 / .45); }
    .ward-play-panel { position: relative; overflow: hidden; border-radius: 16px; box-shadow: 0 20px 60px rgb(0 0 0 / .4); }
    .ward-play-overlay:has(.is-sheet) { align-items: flex-end; }
    .ward-play-panel.is-sheet { border-radius: 20px 20px 0 0; }
    .ward-play-toast { position: absolute; left: 50%; bottom: 20px; z-index: 5; transform: translateX(-50%); margin: 0; padding: 8px 14px; border-radius: 999px;
        background: #f4f4f5; color: #18181b; font: 500 13px system-ui, sans-serif; box-shadow: 0 6px 20px rgb(0 0 0 / .4); }
    .ward-play-toast[hidden] { display: none; }
    /* Cursor da IA (show_in_canvas): desliza até o alvo, nunca teleporta. */
    .ward-ai-cursor { position: fixed; left: 0; top: 0; z-index: 80; pointer-events: none; will-change: transform; }
    .ward-ai-cursor[hidden] { display: none; }
    .ward-ai-arrow { position: absolute; left: -2px; top: -2px; width: 22px; height: 24px; background: #7c3aed;
        clip-path: polygon(0 0, 0 100%, 30% 74%, 52% 100%, 66% 93%, 45% 68%, 82% 68%); filter: drop-shadow(0 1px 1px rgb(0 0 0 / .4)); }
    .ward-ai-arrow::after { content: ''; position: absolute; inset: 3px 5px 5px 3px; background: #a78bfa;
        clip-path: polygon(0 0, 0 100%, 30% 74%, 52% 100%, 66% 93%, 45% 68%, 82% 68%); }
    .ward-ai-label { position: absolute; left: 18px; top: 20px; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        padding: 4px 10px; border-radius: 999px; background: #7c3aed; color: #fff; font: 600 12px system-ui, sans-serif; box-shadow: 0 4px 14px rgb(0 0 0 / .3); }
    .ward-ai-cursor::before { content: ''; position: absolute; left: -18px; top: -18px; width: 36px; height: 36px; border-radius: 999px;
        border: 2px solid #a78bfa; opacity: 0; }
    .ward-ai-cursor.is-click::before { animation: ward-ai-click .6s ease-out; }
    @keyframes ward-ai-click { from { opacity: 1; transform: scale(.3); } to { opacity: 0; transform: scale(1.6); } }
    .ward-follow { position: absolute; z-index: 16; left: 50%; top: 52px; transform: translateX(-50%); display: flex; align-items: center; gap: 8px;
        padding: 5px 6px 5px 12px; border-radius: 999px; background: #18181b; color: #fff; font: 500 13px system-ui, sans-serif; box-shadow: 0 6px 20px rgb(0 0 0 / .25); }
    .ward-follow[hidden] { display: none; }
    .ward-follow-dot { width: 8px; height: 8px; border-radius: 999px; background: #a78bfa; box-shadow: 0 0 0 4px rgb(167 139 250 / .25); }
    .ward-follow.is-paused .ward-follow-dot { background: #71717a; box-shadow: none; }
    .ward-follow button { height: 26px; padding: 0 10px; border: 0; border-radius: 999px; background: #7c3aed; color: #fff; font: 600 12px system-ui, sans-serif; cursor: pointer; }
    .ward-follow:not(.is-paused) button { background: rgb(255 255 255 / .12); }
    .is-list .ward-follow { top: 8px; }
    /* Setas das ligações do protótipo no canvas. */
    .ward-links { position: absolute; left: 0; top: 0; width: 1px; height: 1px; overflow: visible; pointer-events: none; }
    .ward-links path { fill: none; stroke: #2563eb; stroke-linecap: round; }
    .ward-links path.is-overlay { stroke-dasharray: 6 5; }
    .ward-links .ward-link-head { fill: #2563eb; stroke: none; }
    .ward-links rect { fill: rgb(37 99 235 / .12); stroke: #2563eb; }
    .ward-list { display: none; }
    .ward-list-item { display: flex; flex-direction: column; gap: 8px; }
    .ward-list-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 14px; }
    .ward-list-title { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
    .ward-list-open { min-height: 36px; padding: 0 12px; border-radius: 8px; border: 1px solid rgb(127 127 127 / .35); background: none; color: inherit; font: inherit; }
    .ward-list-box { position: relative; overflow: hidden; border-radius: 8px; background: #fff; cursor: pointer;
        box-shadow: 0 0 0 1px rgb(127 127 127 / .25); }
    .ward-list-box iframe { position: absolute; left: 0; top: 0; border: 0; transform-origin: 0 0; pointer-events: none; }
    /* Notas: tamanhos e negrito. */
    .ward-note-sticky.is-size-s { font-size: 14px; } .ward-note-sticky.is-size-l { font-size: 24px; } .ward-note-sticky.is-size-xl { font-size: 32px; }
    .ward-note-title.is-size-s { font-size: 40px; } .ward-note-title.is-size-m { font-size: 56px; } .ward-note-title.is-size-xl { font-size: 96px; }
    .ward-note-sticky.is-bold { font-weight: 700; } .ward-note-title:not(.is-bold) { font-weight: 700; }
    .ward-note-sticky.is-editing, .ward-note-title.is-editing { outline: calc(2px * var(--inv)) solid #38bdf8; outline-offset: 2px; cursor: text; user-select: text; }

    /* Editor (só para quem pode editar). */
    .ward-viewport[data-tool="select"] { cursor: default; }
    .ward-viewport[data-tool="hand"], .ward-viewport[data-space] { cursor: grab; }
    .ward-viewport[data-tool="note"], .ward-viewport[data-tool="title"] { cursor: crosshair; }
    .ward-viewport.is-moving { cursor: move; }
    .ward-viewport[data-tool] .ward-board-name { cursor: default; }
    .ward-viewport[data-tool] .ward-board-name:hover { color: inherit; text-decoration: none; }
    .ward-viewport[data-tool] .ward-note-sticky, .ward-viewport[data-tool] .ward-note-title { user-select: none; }
    .ward-ed-overlay { position: absolute; left: 0; top: 0; pointer-events: none; }
    .ward-ed-sel { position: absolute; box-shadow: 0 0 0 calc(2px * var(--inv)) #38bdf8; pointer-events: none; }
    .ward-ed-group { position: absolute; outline: calc(1px * var(--inv)) dashed #38bdf8; outline-offset: calc(6px * var(--inv)); pointer-events: none; }
    .ward-ed-handle { position: absolute; width: calc(10px * var(--inv)); height: calc(10px * var(--inv)); margin: calc(-5px * var(--inv)) 0 0 calc(-5px * var(--inv));
        background: #fff; border: calc(1.5px * var(--inv)) solid #0ea5e9; border-radius: 2px; pointer-events: auto; box-sizing: border-box; }
    .ward-ed-handle.is-nw { left: 0; top: 0; cursor: nwse-resize; } .ward-ed-handle.is-n { left: 50%; top: 0; cursor: ns-resize; }
    .ward-ed-handle.is-ne { left: 100%; top: 0; cursor: nesw-resize; } .ward-ed-handle.is-e { left: 100%; top: 50%; cursor: ew-resize; }
    .ward-ed-handle.is-se { left: 100%; top: 100%; cursor: nwse-resize; } .ward-ed-handle.is-s { left: 50%; top: 100%; cursor: ns-resize; }
    .ward-ed-handle.is-sw { left: 0; top: 100%; cursor: nesw-resize; } .ward-ed-handle.is-w { left: 0; top: 50%; cursor: ew-resize; }
    .ward-ed-marquee { position: absolute; border: 1px solid #38bdf8; background: rgb(56 189 248 / .12); pointer-events: none; }
    .ward-ed-toolbar, .ward-ed-bar { position: absolute; z-index: 20; display: flex; align-items: center; gap: 2px; padding: 4px; border-radius: 12px;
        background: rgb(255 255 255 / .97); border: 1px solid #e4e4e7; box-shadow: 0 6px 24px rgb(0 0 0 / .14); color: #27272a; font-size: 13px; }
    .dark .ward-ed-toolbar, .dark .ward-ed-bar { background: rgb(24 24 27 / .97); border-color: #3f3f46; color: #e4e4e7; }
    .ward-ed-toolbar { left: 50%; bottom: 16px; transform: translateX(-50%); }
    .ward-ed-bar { transform: translateX(-50%); max-width: calc(100% - 16px); flex-wrap: wrap; justify-content: center; }
    .ward-ed-btn { display: inline-grid; place-items: center; min-width: 36px; height: 36px; padding: 0 8px; border: 0; border-radius: 8px;
        background: none; color: inherit; font: inherit; font-weight: 600; cursor: pointer; }
    .ward-ed-btn svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .ward-ed-btn:hover { background: rgb(127 127 127 / .14); }
    .ward-ed-btn:focus-visible { outline: 2px solid #0ea5e9; outline-offset: 1px; }
    .ward-ed-btn[aria-pressed="true"] { background: #e0f2fe; color: #0369a1; }
    .dark .ward-ed-btn[aria-pressed="true"] { background: rgb(14 165 233 / .22); color: #7dd3fc; }
    .ward-ed-btn:disabled { opacity: .35; cursor: default; background: none; }
    .ward-ed-btn.is-danger:hover { background: #fee2e2; color: #b91c1c; }
    .dark .ward-ed-btn.is-danger:hover { background: rgb(239 68 68 / .2); color: #fca5a5; }
    .ward-ed-btn.is-bold { font-weight: 800; }
    .ward-ed-btn.is-text { padding: 0 10px; font-weight: 500; }
    .ward-ed-sep { width: 1px; height: 22px; margin: 0 4px; background: rgb(127 127 127 / .3); }
    .ward-ed-label { padding: 0 8px; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #52525b; }
    .dark .ward-ed-label { color: #a1a1aa; }
    .ward-ed-status { min-width: 0; max-width: 280px; padding: 0 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #71717a; }
    .ward-ed-status:empty { display: none; }
    .ward-ed-status.is-error { color: #b91c1c; } .dark .ward-ed-status.is-error { color: #fca5a5; }
    .ward-ed-swatches { display: flex; gap: 4px; padding: 0 4px; }
    .ward-ed-swatch { width: 24px; min-width: 24px; height: 24px; padding: 0; border-radius: 999px; box-shadow: inset 0 0 0 1px rgb(0 0 0 / .15); }
    .ward-ed-swatch[aria-pressed="true"] { box-shadow: 0 0 0 2px #0ea5e9; background-clip: padding-box; }
    .ward-ed-rename { font: inherit; font-weight: 600; padding: 0 4px; border: 1px solid #38bdf8; border-radius: 4px; background: #fff; color: #18181b; min-width: 120px; }
    .is-list .ward-ed-toolbar, .is-list .ward-ed-bar { display: none !important; }
    /* Comentários, erros e realce (editor). */
    .ward-board-error { flex: none; margin-left: 4px; padding: 1px 8px; border: 0; border-radius: 999px; background: #dc2626; color: #fff; font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; }
    .ward-board.has-error iframe { box-shadow: 0 0 0 calc(2px * var(--inv)) #dc2626; }
    .ward-ed-hover { position: absolute; pointer-events: none; outline: calc(2px * var(--inv)) solid #f59e0b; background: rgb(245 158 11 / .08); }
    .ward-ed-pins { position: absolute; left: 0; top: 0; }
    .ward-ed-pin { position: absolute; display: grid; place-items: center; width: 28px; height: 28px; margin: -28px 0 0 0; transform: scale(var(--inv)); transform-origin: 0 100%;
        border: 2px solid #fff; border-radius: 14px 14px 14px 2px; background: #7c3aed; color: #fff; font: 700 12px/1 system-ui, sans-serif; cursor: pointer; box-shadow: 0 2px 8px rgb(0 0 0 / .35); }
    .ward-ed-pin.is-resolved { background: #71717a; }
    .ward-ed-pin.is-active { background: #0ea5e9; }
    .ward-ed-pin.is-draft { background: #0ea5e9; pointer-events: none; }
    .ward-viewport[data-tool="comment"] { cursor: crosshair; }
    .ward-ed-count { min-width: 18px; height: 18px; margin-left: 2px; padding: 0 5px; border-radius: 9px; background: #7c3aed; color: #fff; font-size: 11px; line-height: 18px; }
    .ward-ed-btn:has(.ward-ed-count:not([hidden])) { display: inline-flex; align-items: center; gap: 2px; }
    .ward-ed-thread { position: absolute; z-index: 30; width: 320px; max-width: calc(100% - 16px); display: flex; flex-direction: column; gap: 8px; padding: 12px;
        border-radius: 12px; background: #fff; color: #18181b; border: 1px solid #e4e4e7; box-shadow: 0 12px 36px rgb(0 0 0 / .22); font-size: 13px; }
    .dark .ward-ed-thread, .dark .ward-ed-panel { background: #18181b; color: #f4f4f5; border-color: #3f3f46; }
    .ward-ed-thread-head { display: flex; align-items: center; gap: 8px; min-width: 0; }
    .ward-ed-thread-head .ward-ed-muted { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ward-ed-thread-head .ward-ed-btn { min-width: 28px; height: 28px; padding: 0; }
    .ward-ed-messages { display: flex; flex-direction: column; gap: 10px; max-height: 280px; overflow-y: auto; }
    .ward-ed-message p { margin: 2px 0 0; white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.45; }
    .ward-ed-message-head { display: flex; align-items: baseline; gap: 6px; }
    .ward-ed-muted { color: #71717a; font-size: 12px; } .dark .ward-ed-muted { color: #a1a1aa; }
    .ward-ed-badge { padding: 0 6px; border-radius: 999px; background: #ede9fe; color: #5b21b6; font-size: 11px; font-weight: 700; }
    .ward-ed-badge.is-ok { background: #dcfce7; color: #166534; }
    /* Diálogo do editor no lugar de window.confirm/prompt (o MCP App roda num iframe sem modais). */
    .ward-ed-dialog-overlay { position: absolute; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgb(0 0 0 / .35); }
    .ward-ed-dialog { width: 360px; max-width: 100%; display: flex; flex-direction: column; gap: 12px; padding: 16px; border-radius: 12px; border: 1px solid #e4e4e7; background: #fff; color: #18181b; box-shadow: 0 18px 50px rgb(0 0 0 / .25); }
    .dark .ward-ed-dialog { border-color: #3f3f46; background: #18181b; color: #e4e4e7; }
    .ward-ed-dialog-text { margin: 0; font-size: 13px; line-height: 1.45; white-space: pre-line; }
    .ward-ed-dialog .ward-ed-input { min-height: 36px; }
    .ward-ed-dialog-actions { display: flex; justify-content: flex-end; gap: 8px; }
    /* Imagens nos comentários. */
    .ward-ed-attachments { display: flex; flex-wrap: wrap; gap: 6px; }
    .ward-ed-attachments[hidden] { display: none; }
    .ward-ed-attachment { position: relative; width: 64px; height: 64px; border-radius: 8px; overflow: hidden; border: 1px solid rgb(127 127 127 / .3); background: rgb(127 127 127 / .1); }
    .ward-ed-attachment img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .ward-ed-attachment.is-uploading img { opacity: .45; }
    .ward-ed-attachment.is-uploading::after { content: ''; position: absolute; inset: 0; margin: auto; width: 18px; height: 18px; border-radius: 999px;
        border: 2px solid #0ea5e9; border-top-color: transparent; animation: ward-ed-spin .8s linear infinite; }
    @keyframes ward-ed-spin { to { transform: rotate(360deg); } }
    .ward-ed-attachment-remove { position: absolute; top: 2px; right: 2px; width: 20px; height: 20px; padding: 0; border: 0; border-radius: 999px;
        background: rgb(0 0 0 / .65); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
    .ward-ed-attachment-remove svg { width: 12px; height: 12px; }
    .ward-ed-attach { font-size: 15px; }
    .ward-ed-thread.is-dropping { outline: 2px dashed #0ea5e9; outline-offset: -4px; }
    .ward-ed-message-images { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
    .ward-ed-message-image { width: 88px; height: 66px; padding: 0; border: 1px solid rgb(127 127 127 / .3); border-radius: 8px; overflow: hidden; cursor: zoom-in; background: rgb(127 127 127 / .1); }
    .ward-ed-message-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .ward-ed-lightbox { position: fixed; inset: 0; z-index: 90; display: flex; align-items: center; justify-content: center; padding: 24px;
        background: rgb(0 0 0 / .8); cursor: zoom-out; }
    .ward-ed-lightbox img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 8px; box-shadow: 0 20px 60px rgb(0 0 0 / .5); }
    .ward-ed-input { width: 100%; box-sizing: border-box; resize: vertical; min-height: 44px; padding: 8px; border: 1px solid #d4d4d8; border-radius: 8px; font: inherit; color: inherit; background: transparent; }
    .ward-ed-input:focus { outline: 2px solid #0ea5e9; outline-offset: 0; border-color: transparent; }
    .ward-ed-thread-actions { display: flex; align-items: center; gap: 4px; }
    .ward-ed-spacer { flex: 1; }
    .ward-ed-btn.is-primary { padding: 0 12px; background: #0ea5e9; color: #fff; font-weight: 600; }
    .ward-ed-btn.is-primary:hover { background: #0284c7; }
    .ward-ed-error-list { margin: 0; max-height: 200px; overflow: auto; padding: 8px; border-radius: 8px; background: #fef2f2; color: #991b1b; font: 12px/1.45 ui-monospace, monospace; white-space: pre-wrap; }
    .ward-ed-panel { position: absolute; z-index: 25; top: 56px; right: 12px; bottom: 76px; width: 320px; max-width: calc(100% - 24px); display: flex; flex-direction: column; gap: 8px;
        padding: 12px; border-radius: 12px; background: #fff; color: #18181b; border: 1px solid #e4e4e7; box-shadow: 0 12px 36px rgb(0 0 0 / .2); font-size: 13px; }
    .ward-ed-panel-head { display: flex; align-items: center; justify-content: space-between; }
    .ward-ed-panel-head .ward-ed-btn { min-width: 28px; height: 28px; padding: 0; }
    .ward-ed-check { display: flex; align-items: center; gap: 6px; color: #52525b; } .dark .ward-ed-check { color: #a1a1aa; }
    .ward-ed-panel-list { display: flex; flex-direction: column; gap: 6px; overflow-y: auto; }
    .ward-ed-panel-item { display: flex; flex-direction: column; gap: 2px; padding: 8px; border: 1px solid rgb(127 127 127 / .25); border-radius: 8px; background: none; color: inherit; font: inherit; text-align: left; cursor: pointer; }
    .ward-ed-panel-item:hover { border-color: #0ea5e9; }
    .ward-ed-panel-item.is-resolved { opacity: .6; }
    .ward-ed-panel-body { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    .ward-ed-empty { margin: 8px 0; }
    .is-list .ward-ed-thread, .is-list .ward-ed-panel, .is-list .ward-ed-pins { display: none !important; }
    /* Edição visual de elementos. */
    .ward-ed-element { position: absolute; pointer-events: none; outline: calc(2px * var(--inv)) solid #ec4899; }
    .ward-ed-element::before { content: attr(data-tag); position: absolute; left: 0; bottom: 100%; transform: scale(var(--inv)); transform-origin: 0 100%;
        padding: 1px 6px; border-radius: 4px 4px 0 0; background: #ec4899; color: #fff; font: 600 11px/1.4 ui-monospace, monospace; white-space: nowrap; }
    .ward-board.is-editing-text iframe { pointer-events: auto; }
    .ward-ed-props { position: absolute; z-index: 25; top: 56px; right: 12px; bottom: 76px; width: 300px; max-width: calc(100% - 24px); overflow-y: auto;
        display: flex; flex-direction: column; gap: 10px; padding: 12px; border-radius: 12px; background: #fff; color: #18181b; border: 1px solid #e4e4e7;
        box-shadow: 0 12px 36px rgb(0 0 0 / .2); font-size: 12px; }
    .dark .ward-ed-props { background: #18181b; color: #f4f4f5; border-color: #3f3f46; }
    .ward-ed-props-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 13px; }
    .ward-ed-props-head .ward-ed-btn { min-width: 28px; height: 28px; padding: 0; }
    .ward-ed-props-actions { display: flex; flex-wrap: wrap; gap: 2px; }
    .ward-ed-props-actions .ward-ed-btn { min-width: 32px; height: 32px; }
    .ward-ed-snippet { margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ward-ed-hint { margin: 0; padding: 6px 8px; border-radius: 6px; background: rgb(14 165 233 / .1); color: #0369a1; }
    .dark .ward-ed-hint { color: #7dd3fc; }
    .ward-ed-section { display: flex; flex-direction: column; gap: 8px; padding-top: 10px; border-top: 1px solid rgb(127 127 127 / .2); }
    .ward-ed-section h3 { margin: 0; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #71717a; }
    .ward-ed-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .ward-ed-field { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
    .ward-ed-field[hidden] { display: none; }
    .ward-ed-field > span:first-child { color: #71717a; }
    .ward-ed-field input[type="text"], .ward-ed-field select { width: 100%; box-sizing: border-box; height: 30px; padding: 0 8px; border: 1px solid #d4d4d8; border-radius: 6px;
        background: transparent; color: inherit; font: inherit; }
    .dark .ward-ed-field input[type="text"], .dark .ward-ed-field select { border-color: #3f3f46; }
    .dark .ward-ed-field select option { background: #18181b; }
    .ward-ed-field input:focus, .ward-ed-field select:focus { outline: 2px solid #0ea5e9; outline-offset: 0; border-color: transparent; }
    .ward-ed-color { display: flex; gap: 6px; align-items: center; }
    .ward-ed-color input[type="color"] { width: 34px; height: 30px; padding: 2px; border: 1px solid #d4d4d8; border-radius: 6px; background: none; flex: none; }
    .ward-ed-code { font: 12px/1.45 ui-monospace, monospace; }
    .is-list .ward-ed-props, .is-list .ward-ed-element { display: none !important; }
    /* Mini editor de CSS. */
    .ward-ed-props.is-wide { width: 460px; }
    .ward-ed-code-area { display: flex; border: 1px solid #d4d4d8; border-radius: 8px; overflow: hidden; background: #fafafa;
        font: 12px/18px ui-monospace, SFMono-Regular, Menlo, monospace; }
    .dark .ward-ed-code-area { border-color: #3f3f46; background: #0f0f10; }
    .ward-ed-code-area:focus-within { outline: 2px solid #0ea5e9; outline-offset: 0; border-color: transparent; }
    .ward-ed-code-gutter { flex: none; min-width: 26px; padding: 8px 6px; text-align: right; white-space: pre; color: #a1a1aa; background: rgb(127 127 127 / .08); user-select: none; }
    .ward-ed-code-body { position: relative; flex: 1; min-width: 0; }
    .ward-ed-code-hl, .ward-ed-code-input { margin: 0; padding: 8px; font: inherit; white-space: pre-wrap; overflow-wrap: anywhere; tab-size: 2; letter-spacing: 0; }
    .ward-ed-code-hl { position: absolute; inset: 0; pointer-events: none; color: #27272a; overflow: hidden; }
    .dark .ward-ed-code-hl { color: #e4e4e7; }
    .ward-ed-code-input { position: relative; display: block; width: 100%; box-sizing: border-box; min-height: 60px; resize: none; border: 0; outline: 0;
        background: transparent; color: transparent; caret-color: #0ea5e9; overflow: hidden; }
    .ward-ed-code-input::placeholder { color: #a1a1aa; }
    .ward-ed-code-hl .p { color: #0369a1; } .dark .ward-ed-code-hl .p { color: #7dd3fc; }
    .ward-ed-code-hl .v { color: #b45309; } .dark .ward-ed-code-hl .v { color: #fcd34d; }
    .ward-ed-code-hl .s { color: #7c3aed; } .dark .ward-ed-code-hl .s { color: #c4b5fd; }
    .ward-ed-code-hl .c { color: #a1a1aa; font-style: italic; }
    .ward-ed-rules { display: flex; flex-direction: column; gap: 8px; }
    .ward-ed-rule { display: flex; flex-direction: column; gap: 4px; }
    .ward-ed-rule-head { display: flex; align-items: center; gap: 4px; }
    .ward-ed-rule-head code { color: #71717a; font-size: 11px; }
    .ward-ed-rule-head h3 { margin: 0; }
    .ward-ed-btn.is-small { height: 26px; min-width: 0; padding: 0 8px; font-size: 12px; }
    /* Imagens e exportação. */
    .ward-viewport.is-dropping { outline: 3px dashed #0ea5e9; outline-offset: -8px; }
    .ward-ed-menu { position: absolute; z-index: 30; min-width: 240px; display: flex; flex-direction: column; padding: 6px; border-radius: 12px;
        background: #fff; color: #18181b; border: 1px solid #e4e4e7; box-shadow: 0 12px 36px rgb(0 0 0 / .22); font-size: 13px; }
    .dark .ward-ed-menu { background: #18181b; color: #f4f4f5; border-color: #3f3f46; }
    .ward-ed-menu-label { padding: 6px 10px; color: #71717a; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ward-ed-menu-item { min-height: 36px; padding: 0 10px; border: 0; border-radius: 8px; background: none; color: inherit; font: inherit; text-align: left; cursor: pointer; }
    .ward-ed-menu-item:hover:not(:disabled), .ward-ed-menu-item:focus-visible { background: rgb(127 127 127 / .14); outline: none; }
    .ward-ed-menu-item:disabled { opacity: .4; cursor: default; }
    .ward-ed-menu-sep { height: 1px; margin: 4px 0; background: rgb(127 127 127 / .25); }
    .ward-ed-assets { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; overflow-y: auto; }
    .ward-ed-asset-cell { position: relative; }
    .ward-ed-asset { display: block; width: 100%; aspect-ratio: 1; padding: 0; border: 1px solid rgb(127 127 127 / .3); border-radius: 8px; overflow: hidden;
        background: repeating-conic-gradient(#e4e4e7 0 25%, #fff 0 50%) 0 0 / 12px 12px; cursor: pointer; }
    .ward-ed-asset:hover, .ward-ed-asset:focus-visible { border-color: #0ea5e9; outline: none; }
    .ward-ed-asset img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .ward-ed-btn.ward-ed-asset-remove { position: absolute; top: 4px; right: 4px; min-width: 24px; height: 24px; padding: 0; border-radius: 6px; background: rgb(0 0 0 / .55); color: #fff; opacity: 0; }
    .ward-ed-asset-cell:hover .ward-ed-asset-remove, .ward-ed-asset-remove:focus-visible { opacity: 1; }
    .ward-ed-asset-remove svg { width: 14px; height: 14px; }
    /* Páginas e filtro. */
    .ward-pages { position: absolute; z-index: 15; top: 10px; left: 50%; transform: translateX(-50%); display: flex; gap: 2px; padding: 3px; max-width: calc(100% - 420px);
        overflow-x: auto; border-radius: 10px; background: rgb(255 255 255 / .95); border: 1px solid #e4e4e7; box-shadow: 0 2px 10px rgb(0 0 0 / .08); }
    .dark .ward-pages { background: rgb(24 24 27 / .95); border-color: #3f3f46; }
    .ward-page-tab { flex: none; height: 30px; padding: 0 12px; border: 0; border-radius: 7px; background: none; color: #52525b; font: 500 13px/1 system-ui, sans-serif; cursor: pointer; white-space: nowrap; }
    .dark .ward-page-tab { color: #a1a1aa; }
    .ward-page-tab:hover { background: rgb(127 127 127 / .12); }
    .ward-page-tab[aria-current="page"] { background: #18181b; color: #fff; } .dark .ward-page-tab[aria-current="page"] { background: #f4f4f5; color: #18181b; }
    .ward-filter-box { position: absolute; z-index: 15; top: 10px; right: 12px; display: flex; align-items: center; gap: 6px; }
    .ward-filter { width: 190px; height: 34px; padding: 0 10px; border-radius: 9px; border: 1px solid #e4e4e7; background: rgb(255 255 255 / .95); color: #18181b; font: 13px system-ui, sans-serif; }
    .dark .ward-filter { border-color: #3f3f46; background: rgb(24 24 27 / .95); color: #f4f4f5; }
    .ward-filter:focus { outline: 2px solid #0ea5e9; outline-offset: 0; }
    .ward-filter-count { padding: 2px 8px; border-radius: 999px; background: #0ea5e9; color: #fff; font: 600 12px system-ui, sans-serif; }
    .ward-board.is-filtered-out { opacity: .15; }
    .is-list .ward-pages, .is-list .ward-filter-box { display: none; }
    .ward-page-add { color: #0369a1; } .dark .ward-page-add { color: #7dd3fc; }
    .ward-ed-select { height: 32px; padding: 0 8px; border: 1px solid rgb(127 127 127 / .35); border-radius: 8px; background: transparent; color: inherit; font: inherit; }
    .ward-ed-token { display: grid; grid-template-columns: 110px 1fr auto; align-items: center; gap: 6px; }
    .ward-ed-token code { overflow: hidden; text-overflow: ellipsis; font-size: 11px; }
    .ward-ed-token input[type="text"], .ward-ed-row > input { width: 100%; box-sizing: border-box; height: 30px; padding: 0 8px; border: 1px solid #d4d4d8; border-radius: 6px; background: transparent; color: inherit; font: inherit; }
    /* Design system. */
    .ward-ds-bar { position: absolute; z-index: 50; top: 52px; left: 12px; }
    .ward-ds-open { display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px; border-radius: 999px; border: 1px solid #bae6fd;
        background: #f0f9ff; color: #0369a1; font: 600 13px system-ui, sans-serif; cursor: pointer; }
    .dark .ward-ds-open { border-color: #075985; background: rgb(14 165 233 / .15); color: #7dd3fc; }
    .ward-ds-docs { font-size: 15px; line-height: 1.6; }
    .ward-ds-docs h1, .ward-ds-docs h2, .ward-ds-docs h3 { margin: 1.4em 0 .5em; font-weight: 700; line-height: 1.25; }
    .ward-ds-docs h1 { font-size: 1.6em; } .ward-ds-docs h2 { font-size: 1.3em; } .ward-ds-docs h3 { font-size: 1.1em; }
    .ward-ds-docs p, .ward-ds-docs ul, .ward-ds-docs ol { margin: .6em 0; } .ward-ds-docs ul { list-style: disc; padding-left: 1.4em; } .ward-ds-docs ol { list-style: decimal; padding-left: 1.4em; }
    .ward-ds-docs code { padding: .1em .35em; border-radius: 4px; background: rgb(127 127 127 / .15); font-size: .9em; }
    .ward-ds-docs pre { padding: 12px; border-radius: 8px; background: rgb(127 127 127 / .12); overflow-x: auto; }
    .ward-ds-docs pre code { padding: 0; background: none; }
    .ward-ds-docs a { color: #0284c7; text-decoration: underline; }
    [x-cloak] { display: none !important; }
    .ward-ed-components .ward-filter { width: 100%; box-sizing: border-box; }
    .ward-ed-link { color: #0284c7; font-size: 12px; } .dark .ward-ed-link { color: #7dd3fc; }
    .ward-ed-cmp-list { display: flex; flex-direction: column; gap: 10px; overflow-y: auto; }
    .ward-ed-cmp { display: flex; flex-direction: column; gap: 4px; padding: 8px; border: 1px solid rgb(127 127 127 / .25); border-radius: 10px; }
    .ward-ed-cmp p { margin: 0; }
    .ward-ed-cmp-preview { position: relative; overflow: hidden; border-radius: 6px; background: repeating-conic-gradient(#e4e4e7 0 25%, #fff 0 50%) 0 0 / 12px 12px; }
    .ward-ed-cmp-preview iframe { position: absolute; left: 0; top: 0; border: 0; transform-origin: 0 0; pointer-events: none; background: transparent; }
    @media (max-width: 1100px) { .ward-ed-toolbar { left: 12px; transform: none; max-width: calc(100% - 200px); overflow-x: auto; } }
    /* Artboard sem fundo próprio (componente de design system): transparente, com contorno tracejado. */
    /* color-scheme igual ao do artboard: senão o Chrome pinta o fundo do iframe (página escura x artboard claro). */
    .ward-board iframe, .ward-list-box iframe, .ward-ed-cmp-preview iframe { color-scheme: normal; }
    .ward-board.is-transparent iframe, .ward-list-box.is-transparent, .ward-list-box.is-transparent iframe { background: transparent; }
    .ward-board.is-transparent iframe { box-shadow: none; outline: calc(1px * var(--inv)) dashed rgb(127 127 127 / .55); outline-offset: 0; }
    .ward-board.is-transparent.is-selected iframe { outline: calc(2px * var(--inv)) solid #38bdf8; }
    /* Modo lista (tela estreita): o canvas liga a classe conforme a largura. */
    .is-list .ward-viewport, .is-list .ward-zoom { display: none !important; }
    .is-list .ward-list { display: flex; flex-direction: column; align-items: center; gap: 24px; position: absolute; inset: 0; overflow-y: auto; padding: 52px 16px 16px; }
    .is-list .ward-list-item { width: 100%; align-items: center; }
    .is-list .ward-list-head { width: 100%; }
</style>
