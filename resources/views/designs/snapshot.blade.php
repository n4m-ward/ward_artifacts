{{-- O canvas inteiro, estático, para o print do servidor (get_design_image). --}}
<!DOCTYPE html>
<html lang="pt-BR" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $artifact->title }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/design-canvas.js'])
        @include('designs.canvas-styles')
    </head>
    <body class="h-dvh overflow-hidden bg-zinc-800 text-zinc-100">
        <main class="relative h-full" data-design-canvas>
            <div class="ward-viewport" data-canvas-viewport>
                <div class="ward-world" data-canvas-world>
                    <div data-canvas-notes></div>
                    <div data-canvas-boards></div>
                </div>
            </div>
            <div class="ward-list" data-canvas-list aria-label="Artboards"></div>
            <p data-empty @if ($artifact->boards()->exists()) hidden @endif class="absolute inset-0 flex items-center justify-center text-sm text-zinc-500">
                Nenhum artboard ainda.
            </p>
        </main>

        <script type="application/json" id="ward-design-config">@json($config)</script>
    </body>
</html>
