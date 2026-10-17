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
    /* Modo lista (tela estreita): o canvas liga a classe conforme a largura. */
    .is-list .ward-viewport, .is-list .ward-zoom { display: none !important; }
    .is-list .ward-list { display: flex; flex-direction: column; align-items: center; gap: 24px; position: absolute; inset: 0; overflow-y: auto; padding: 52px 16px 16px; }
    .is-list .ward-list-item { width: 100%; align-items: center; }
    .is-list .ward-list-head { width: 100%; }
</style>
