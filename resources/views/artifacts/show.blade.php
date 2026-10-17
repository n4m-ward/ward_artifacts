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
        <header class="flex h-12 shrink-0 items-center gap-3 border-b border-zinc-200 bg-white px-3 dark:border-zinc-700 dark:bg-zinc-900">
            <a href="{{ auth()->check() || ($preview ?? false) ? route('dashboard') : route('home') }}" class="flex size-7 shrink-0 items-center justify-center rounded-md bg-[#0E0E0E] text-[#ECECEC] dark:ring-1 dark:ring-white/10" aria-label="{{ config('app.name') }}">
                <x-app-logo-icon class="size-4" />
            </a>

            <div class="min-w-0 flex-1 leading-tight">
                <h1 class="truncate text-sm font-medium">{{ $artifact->title }}</h1>
                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                    {{ '@'.$owner->username }} · {{ $artifact->project->name }} · v{{ $artifact->currentVersion->number }}
                </p>
            </div>

            @if ($preview ?? false)
                <flux:badge size="sm" color="amber" icon="eye" title="Link de uso único gerado pelo MCP">Pré-visualização</flux:badge>
            @else
                <livewire:artifacts.manage :artifact="$artifact" :content-url="$contentUrl" />
            @endif
        </header>

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
