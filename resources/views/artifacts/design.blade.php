@php
    $owner = $artifact->user;
    $boardCount = count($boards);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $artifact->title.' · '.config('app.name')])
        @vite($editable ? ['resources/js/design-canvas.js', 'resources/js/design-editor.js'] : ['resources/js/design-canvas.js'])
        <meta name="robots" content="noindex, nofollow">
        <meta name="description" content="Design &quot;{{ $artifact->title }}&quot; de {{ '@'.$owner->username }} com {{ $boardCount }} artboard(s).">
        @foreach ($boards as $board)
            {{-- Para IAs e ferramentas que não executam o canvas: o HTML de cada artboard. --}}
            <link rel="alternate" type="text/html" title="Artboard {{ $board['name'] }}" href="{{ $board['url'] }}">
        @endforeach
        @include('designs.canvas-styles')
    </head>
    <body class="flex h-dvh flex-col overflow-hidden bg-white text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">
        @include('artifacts.partials.bar', [
            'meta' => '@'.$owner->username.' · '.$artifact->project->name.' · '.($artifact->isDesignSystem() ? 'design system' : 'design'),
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

            @if ($artifact->isDesignSystem() && auth()->check())
                <div class="ward-ds-bar">@include('designs.system-docs')</div>
            @endif

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
