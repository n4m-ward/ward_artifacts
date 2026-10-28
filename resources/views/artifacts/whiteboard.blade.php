@php
    $owner = $artifact->user;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $artifact->title.' · '.config('app.name')])
        <meta name="robots" content="noindex, nofollow">
        <meta name="description" content="Whiteboard &quot;{{ $artifact->title }}&quot; de {{ '@'.$owner->username }} com {{ $elementCount }} elemento(s).">
        {{-- Fontes do Excalidraw servidas pelo app (copiadas no build). --}}
        <script>window.EXCALIDRAW_ASSET_PATH = @json($config['assetPath']);</script>
        @vite(['resources/js/whiteboard/main.jsx'])
    </head>
    <body class="flex h-dvh flex-col overflow-hidden bg-white text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">
        @include('artifacts.partials.bar', [
            'meta' => '@'.$owner->username.' · '.$artifact->project->name.' · whiteboard',
        ])

        <main class="ward-whiteboard relative min-h-0 flex-1 bg-white text-zinc-900" data-whiteboard>
            <p class="flex h-full items-center justify-center text-sm text-zinc-500">Carregando o quadro…</p>
        </main>

        <script type="application/json" id="ward-whiteboard-config">@json($config)</script>

        @fluxScripts
    </body>
</html>
