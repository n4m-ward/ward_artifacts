@php
    $owner = $artifact->user;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $artifact->title.' · '.config('app.name')])
        <meta name="robots" content="noindex, nofollow">
        <meta name="description" content="Artifact &quot;{{ $artifact->title }}&quot; de {{ '@'.$owner->username }}. O HTML roda no iframe; o arquivo bruto está em {{ $contentUrl }}">
        {{-- Para IAs e ferramentas que não executam iframes: o HTML bruto do artifact. --}}
        <link rel="alternate" type="text/html" title="HTML do artifact" href="{{ $contentUrl }}">
    </head>
    <body class="flex h-dvh flex-col overflow-hidden bg-white text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">
        @include('artifacts.partials.bar', [
            'meta' => '@'.$owner->username.' · '.$artifact->project->name.' · v'.$artifact->currentVersion->number,
        ])

        <main class="relative min-h-0 flex-1">
            <iframe
                src="{{ $contentUrl }}"
                title="{{ $artifact->title }}"
                sandbox="{{ $sandbox }}"
                allow="fullscreen; autoplay; clipboard-read; clipboard-write; encrypted-media; gamepad; picture-in-picture"
                referrerpolicy="no-referrer"
                class="absolute inset-0 size-full border-0 bg-white"
            ></iframe>

            <noscript>
                <p class="p-4 text-sm">O HTML deste artifact está em <a class="underline" href="{{ $contentUrl }}">{{ $contentUrl }}</a>.</p>
            </noscript>
        </main>

        @fluxScripts
    </body>
</html>
