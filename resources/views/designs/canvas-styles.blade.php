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
    .ward-focus-box { position: relative; overflow: hidden; background: #fff; box-shadow: 0 10px 40px rgb(0 0 0 / .4); }
    .ward-focus-box iframe { display: block; border: 0; }
    .ward-focus-page { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; background: #fff; }
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
    /* Modo lista (tela estreita): o canvas liga a classe conforme a largura. */
    .is-list .ward-viewport, .is-list .ward-zoom { display: none !important; }
    .is-list .ward-list { display: flex; flex-direction: column; align-items: center; gap: 24px; position: absolute; inset: 0; overflow-y: auto; padding: 52px 16px 16px; }
    .is-list .ward-list-item { width: 100%; align-items: center; }
    .is-list .ward-list-head { width: 100%; }
</style>
