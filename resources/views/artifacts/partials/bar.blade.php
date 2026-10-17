{{-- Barra superior do viewer (página e design). --}}
<header class="flex h-12 shrink-0 items-center gap-3 border-b border-zinc-200 bg-white px-3 dark:border-zinc-700 dark:bg-zinc-900">
    <a href="{{ auth()->check() || ($preview ?? false) ? route('dashboard') : route('home') }}" class="flex size-7 shrink-0 items-center justify-center rounded-md bg-[#0E0E0E] text-[#ECECEC] dark:ring-1 dark:ring-white/10" aria-label="{{ config('app.name') }}">
        <x-app-logo-icon class="size-4" />
    </a>

    <div class="min-w-0 flex-1 leading-tight">
        <h1 class="truncate text-sm font-medium">{{ $artifact->title }}</h1>
        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $meta }}</p>
    </div>

    @if ($preview ?? false)
        <flux:badge size="sm" color="amber" icon="eye" title="Link de uso único gerado pelo MCP">Pré-visualização</flux:badge>
    @else
        <livewire:artifacts.manage :artifact="$artifact" :content-url="$contentUrl ?? ''" />
    @endif
</header>
