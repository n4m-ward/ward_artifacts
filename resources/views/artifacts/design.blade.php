@php
    $owner = $artifact->user;
    $boardCount = count($boards);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $artifact->title.' · '.config('app.name')])
        <meta name="robots" content="noindex, nofollow">
        <meta name="description" content="Design &quot;{{ $artifact->title }}&quot; de {{ '@'.$owner->username }} com {{ $boardCount }} artboard(s).">
        @foreach ($boards as $board)
            {{-- Para IAs e ferramentas que não executam iframes: o HTML de cada artboard. --}}
            <link rel="alternate" type="text/html" title="Artboard {{ $board['name'] }}" href="{{ $board['url'] }}">
        @endforeach
    </head>
    <body class="flex h-dvh flex-col overflow-hidden bg-white text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">
        @include('artifacts.partials.bar', [
            'meta' => '@'.$owner->username.' · '.$artifact->project->name.' · design · '.$boardCount.' artboard'.($boardCount === 1 ? '' : 's'),
        ])

        @if ($boardCount === 0)
            <main class="flex flex-1 items-center justify-center p-6 text-center text-sm text-zinc-500">
                Nenhum artboard ainda. Assim que a IA criar o primeiro, ele aparece aqui.
            </main>
        @else
            <main
                id="ward-canvas"
                class="relative min-h-0 flex-1 overflow-auto bg-zinc-100 dark:bg-[#111111]"
                style="background-image: radial-gradient(circle, rgb(127 127 127 / 0.25) 1px, transparent 1px); background-size: 24px 24px;"
                data-width="{{ $bounds['w'] }}"
                data-height="{{ $bounds['h'] }}"
            >
                <div id="ward-world" class="absolute left-0 top-0 origin-top-left" style="width: {{ $bounds['w'] }}px; height: {{ $bounds['h'] }}px;">
                    @foreach ($notes as $note)
                        @if ($note['kind'] === 'title')
                            <div class="absolute whitespace-pre-line font-semibold tracking-tight text-zinc-800 dark:text-zinc-100" style="left: {{ $note['left'] }}px; top: {{ $note['top'] }}px; font-size: 64px; line-height: 1.1; max-width: {{ $note['w'] ?? 2400 }}px;">{{ $note['text'] }}</div>
                        @else
                            <div class="absolute whitespace-pre-line rounded-lg p-4 text-base leading-snug text-zinc-900 shadow-sm" style="left: {{ $note['left'] }}px; top: {{ $note['top'] }}px; width: {{ $note['w'] ?? 260 }}px; background: {{ $note['background'] }};">{{ $note['text'] }}</div>
                        @endif
                    @endforeach

                    @foreach ($boards as $board)
                        <figure class="absolute m-0" style="left: {{ $board['left'] }}px; top: {{ $board['top'] }}px; width: {{ $board['w'] }}px;">
                            <figcaption class="absolute -top-9 left-0 flex max-w-full items-center gap-2 truncate text-[15px] text-zinc-500 dark:text-zinc-400">
                                <span class="truncate font-medium text-zinc-700 dark:text-zinc-200">{{ $board['title'] ?: $board['name'] }}</span>
                                <span>{{ $board['w'] }}×{{ $board['h'] }}</span>
                                @if ($board['interactive'])
                                    <span class="rounded bg-sky-500/15 px-1.5 text-sky-600 dark:text-sky-300">interativo</span>
                                @endif
                                <a href="{{ $board['url'] }}" target="_blank" rel="noopener noreferrer" class="underline-offset-2 hover:underline">abrir</a>
                            </figcaption>
                            <iframe
                                src="{{ $board['url'] }}"
                                title="{{ $board['title'] ?: $board['name'] }}"
                                sandbox="{{ $sandbox }}"
                                allow="fullscreen; autoplay; clipboard-read; clipboard-write; encrypted-media; gamepad; picture-in-picture"
                                referrerpolicy="no-referrer"
                                loading="lazy"
                                class="block border-0 bg-white shadow-sm ring-1 ring-black/10 dark:ring-white/10"
                                style="width: {{ $board['w'] }}px; height: {{ $board['h'] }}px;"
                            ></iframe>
                        </figure>
                    @endforeach
                </div>

                <div class="fixed bottom-4 right-4 flex items-center gap-1 rounded-lg border border-zinc-200 bg-white/95 p-1 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/95" role="toolbar" aria-label="Zoom">
                    <flux:button size="sm" variant="ghost" icon="minus" aria-label="Diminuir zoom" data-zoom="out" />
                    <button type="button" data-zoom="reset" class="min-w-14 rounded px-2 text-xs tabular-nums text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800" aria-label="Zoom 100%"><span id="ward-zoom">100%</span></button>
                    <flux:button size="sm" variant="ghost" icon="plus" aria-label="Aumentar zoom" data-zoom="in" />
                    <flux:button size="sm" variant="ghost" icon="arrows-pointing-out" aria-label="Ajustar tudo na tela" data-zoom="fit" />
                </div>
            </main>

            <script>
                (() => {
                    const canvas = document.getElementById('ward-canvas');
                    const world = document.getElementById('ward-world');
                    const label = document.getElementById('ward-zoom');
                    const width = Number(canvas.dataset.width);
                    const height = Number(canvas.dataset.height);
                    let scale = 1;

                    // O mundo é absoluto e escalado; este espaçador dá a área de rolagem.
                    const spacer = document.createElement('div');
                    spacer.style.pointerEvents = 'none';
                    canvas.appendChild(spacer);

                    const apply = () => {
                        world.style.transform = `scale(${scale})`;
                        spacer.style.width = `${width * scale}px`;
                        spacer.style.height = `${height * scale}px`;
                        label.textContent = `${Math.round(scale * 100)}%`;
                    };

                    const fit = () => {
                        const sx = (canvas.clientWidth - 32) / width;
                        const sy = (canvas.clientHeight - 32) / height;
                        scale = Math.max(0.05, Math.min(1, sx, sy));
                        apply();
                        canvas.scrollTo(0, 0);
                    };

                    canvas.addEventListener('click', (event) => {
                        const button = event.target.closest('[data-zoom]');
                        if (!button) return;
                        const action = button.dataset.zoom;
                        if (action === 'fit') return fit();
                        scale = action === 'reset' ? 1 : Math.max(0.05, Math.min(4, scale * (action === 'in' ? 1.25 : 0.8)));
                        apply();
                    });

                    fit();
                })();
            </script>
        @endif

        @fluxScripts
    </body>
</html>
