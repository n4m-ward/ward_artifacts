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
    <body class="flex h-dvh flex-col overflow-hidden bg-white text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">
        <header class="flex min-h-12 shrink-0 flex-wrap items-center gap-x-3 gap-y-1 border-b border-zinc-200 px-3 py-1.5 sm:px-4 dark:border-zinc-700">
            <a href="{{ auth()->check() ? route('dashboard') : route('home') }}" class="flex shrink-0 items-center" aria-label="{{ config('app.name') }}">
                <x-app-logo-icon class="size-6 fill-current" />
            </a>

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-sm font-semibold">{{ $artifact->title }}</h1>
                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                    {{ '@'.$owner->username }} · {{ $artifact->project->name }} · v{{ $artifact->currentVersion->number }}
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
                <livewire:artifacts.manage :artifact="$artifact" />

                <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square" :href="$contentUrl" target="_blank" rel="noopener noreferrer" title="Abrir o HTML puro em nova aba">
                    <span class="max-sm:hidden">HTML puro</span>
                </flux:button>

                <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('artifacts.download', $artifact)" title="Baixar o código">
                    <span class="max-sm:hidden">Baixar</span>
                </flux:button>
            </div>
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
