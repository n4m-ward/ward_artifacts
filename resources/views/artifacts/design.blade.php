@php
    $owner = $artifact->user;
    $boardCount = count($boards);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $artifact->title.' · '.config('app.name')])
        @vite('resources/js/design-canvas.js')
        <meta name="robots" content="noindex, nofollow">
        <meta name="description" content="Design &quot;{{ $artifact->title }}&quot; de {{ '@'.$owner->username }} com {{ $boardCount }} artboard(s).">
        @foreach ($boards as $board)
            {{-- Para IAs e ferramentas que não executam o canvas: o HTML de cada artboard. --}}
            <link rel="alternate" type="text/html" title="Artboard {{ $board['name'] }}" href="{{ $board['url'] }}">
        @endforeach
        <style>
            .ward-viewport { position: absolute; inset: 0; overflow: hidden; touch-action: none; cursor: grab;
                background-color: #f4f4f5; background-image: radial-gradient(circle, rgb(120 120 120 / .28) 1px, transparent 1px); background-size: 24px 24px; }
            .dark .ward-viewport { background-color: #111111; background-image: radial-gradient(circle, rgb(160 160 160 / .16) 1px, transparent 1px); }
            .ward-viewport.is-panning { cursor: grabbing; }
            .ward-world { position: absolute; left: 0; top: 0; transform-origin: 0 0; --inv: 1; }
            .ward-board { position: absolute; }
            .ward-board iframe { display: block; border: 0; background: #fff; pointer-events: none;
                box-shadow: 0 1px 3px rgb(0 0 0 / .18), 0 0 0 1px rgb(0 0 0 / .08); }
            .dark .ward-board iframe { box-shadow: 0 1px 3px rgb(0 0 0 / .5), 0 0 0 1px rgb(255 255 255 / .08); }
            .ward-board.is-selected iframe { box-shadow: 0 0 0 calc(2px * var(--inv)) #38bdf8; }
            .ward-board.is-updated iframe { box-shadow: 0 0 0 calc(3px * var(--inv)) #f59e0b; transition: box-shadow .3s; }
            .ward-board-caption { position: absolute; left: 0; bottom: 100%; display: flex; align-items: baseline; gap: 8px;
                padding-bottom: 8px; white-space: nowrap; transform: scale(var(--inv)); transform-origin: 0 100%; font-size: 13px; }
            .ward-board-name { font: inherit; font-weight: 600; color: #3f3f46; background: none; border: 0; padding: 0; cursor: pointer; }
            .dark .ward-board-name { color: #e4e4e7; }
            .ward-board-name:hover, .ward-board-name:focus-visible { color: #0284c7; text-decoration: underline; }
            .ward-board-meta { color: #71717a; }
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
            @media (max-width: 639px) {
                .ward-viewport, .ward-zoom { display: none !important; }
                .ward-list { display: flex; flex-direction: column; gap: 24px; position: absolute; inset: 0; overflow-y: auto; padding: 16px; }
            }
        </style>
    </head>
    <body class="flex h-dvh flex-col overflow-hidden bg-white text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">
        @include('artifacts.partials.bar', [
            'meta' => '@'.$owner->username.' · '.$artifact->project->name.' · design',
        ])

        <main class="relative min-h-0 flex-1" data-design-canvas>
            <div class="ward-viewport" data-canvas-viewport>
                <div class="ward-world" data-canvas-world>
                    <div data-canvas-notes></div>
                    <div data-canvas-boards></div>
                </div>
            </div>

            <div class="ward-list" data-canvas-list aria-label="Artboards"></div>

            <p data-empty @if ($boardCount > 0) hidden @endif class="pointer-events-none absolute inset-0 flex items-center justify-center p-6 text-center text-sm text-zinc-500">
                Nenhum artboard ainda. Assim que a IA criar o primeiro, ele aparece aqui sozinho.
            </p>

            <div class="pointer-events-none absolute left-3 top-3 flex items-center gap-2 rounded-full border border-zinc-200 bg-white/90 px-3 py-1 text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900/90 dark:text-zinc-300">
                <span class="size-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
                <span>Ao vivo</span>
                <span aria-hidden="true">·</span>
                <span data-board-count>{{ $boardCount }} artboard{{ $boardCount === 1 ? '' : 's' }}</span>
            </div>

            <p data-banner hidden role="status" class="absolute left-1/2 top-3 -translate-x-1/2 rounded-lg bg-amber-100 px-3 py-2 text-sm text-amber-900"></p>

            <div class="ward-zoom absolute bottom-4 right-4 flex items-center gap-1 rounded-lg border border-zinc-200 bg-white/95 p-1 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/95" role="toolbar" aria-label="Zoom" data-zoom-toolbar>
                <flux:button size="sm" variant="ghost" icon="minus" aria-label="Diminuir zoom" data-zoom="out" />
                <button type="button" data-zoom="reset" class="min-h-8 min-w-14 rounded px-2 text-xs tabular-nums text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800" aria-label="Zoom 100%"><span data-zoom-label>100%</span></button>
                <flux:button size="sm" variant="ghost" icon="plus" aria-label="Aumentar zoom" data-zoom="in" />
                <flux:button size="sm" variant="ghost" icon="arrows-pointing-out" aria-label="Ajustar tudo na tela" data-zoom="fit" />
            </div>
        </main>

        <div data-focus hidden class="fixed inset-0 z-50 flex flex-col bg-[#0b0b0b] text-zinc-100" role="dialog" aria-modal="true" aria-label="Artboard em foco">
            <div class="flex h-12 shrink-0 items-center gap-2 border-b border-white/10 px-3">
                <div class="min-w-0 flex-1 leading-tight">
                    <div class="truncate text-sm font-medium" data-focus-title></div>
                    <div class="truncate text-xs text-zinc-400" data-focus-meta></div>
                </div>
                <flux:button size="sm" variant="ghost" icon="chevron-left" aria-label="Artboard anterior" data-focus-prev />
                <flux:button size="sm" variant="ghost" icon="chevron-right" aria-label="Próximo artboard" data-focus-next />
                <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square" aria-label="Abrir sozinho em nova aba" data-focus-open target="_blank" rel="noopener noreferrer" href="#" />
                <flux:button size="sm" variant="ghost" icon="x-mark" aria-label="Fechar (Esc)" data-focus-close />
            </div>
            <div class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden" data-focus-stage></div>
        </div>

        <script type="application/json" id="ward-design-config">@json($config)</script>

        @fluxScripts
    </body>
</html>
