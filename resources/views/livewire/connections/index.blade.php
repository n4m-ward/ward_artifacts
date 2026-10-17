<?php

use App\Actions\Mcp\CreatePersonalMcpToken;
use App\Actions\Mcp\RevokeMcpConnection;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

/**
 * Conexões MCP: clientes OAuth autorizados (Claude, Codex, OpenCode...) e
 * tokens pessoais. Admins também veem as conexões de todo mundo.
 */
new class extends Component {
    public string $tokenName = '';

    public ?string $newToken = null;

    #[Computed]
    public function mcpUrl(): string
    {
        return url('/mcp');
    }

    /**
     * Uma linha por (usuário, cliente) com acesso ainda válido.
     */
    #[Computed]
    public function connections(): Collection
    {
        return $this->activeTokens()
            ->where('t.user_id', Auth::id())
            ->get()
            ->groupBy('client_id')
            ->map(fn (Collection $tokens) => $this->summarize($tokens))
            ->sortByDesc('last_used_at')
            ->values();
    }

    #[Computed]
    public function allConnections(): Collection
    {
        if (! Auth::user()->isAdmin()) {
            return collect();
        }

        $users = User::pluck('username', 'id');

        return $this->activeTokens()
            ->where('t.user_id', '!=', Auth::id())
            ->get()
            ->groupBy(fn ($token) => $token->user_id.'|'.$token->client_id)
            ->map(fn (Collection $tokens) => $this->summarize($tokens) + ['username' => $users[$tokens->first()->user_id] ?? '?'])
            ->sortByDesc('last_used_at')
            ->values();
    }

    public function revoke(string $clientId, ?int $userId, RevokeMcpConnection $revoke): void
    {
        $owner = $userId && $userId !== Auth::id() ? User::findOrFail($userId) : Auth::user();

        abort_unless($owner->is(Auth::user()) || Auth::user()->isAdmin(), 403);

        $revoke->forClient($owner, $clientId);

        unset($this->connections, $this->allConnections);
    }

    public function revokeToken(string $tokenId): void
    {
        $token = DB::table('oauth_access_tokens')->where('id', $tokenId)->first();

        abort_unless($token && ((int) $token->user_id === Auth::id() || Auth::user()->isAdmin()), 403);

        DB::table('oauth_access_tokens')->where('id', $tokenId)->update(['revoked' => true]);

        unset($this->connections, $this->allConnections);
    }

    public function createToken(CreatePersonalMcpToken $create): void
    {
        $this->validate(['tokenName' => ['required', 'string', 'max:100']], attributes: ['tokenName' => 'nome']);

        $this->newToken = $create->handle(Auth::user(), $this->tokenName)->accessToken;
        $this->reset('tokenName');

        unset($this->connections);
    }

    private function activeTokens()
    {
        return DB::table('oauth_access_tokens as t')
            ->join('oauth_clients as c', 'c.id', '=', 't.client_id')
            ->where('t.revoked', false)
            ->where(fn ($query) => $query
                ->where('t.expires_at', '>', now())
                ->orWhereExists(fn ($query) => $query->select(DB::raw(1))
                    ->from('oauth_refresh_tokens as r')
                    ->whereColumn('r.access_token_id', 't.id')
                    ->where('r.revoked', false)
                    ->where('r.expires_at', '>', now())))
            ->select('t.id', 't.user_id', 't.client_id', 't.name', 't.created_at', 't.last_used_at', 't.expires_at', 'c.name as client_name', 'c.grant_types');
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Collection $tokens): array
    {
        $first = $tokens->first();
        $personal = str_contains((string) $first->grant_types, 'personal_access');

        return [
            'client_id' => $first->client_id,
            'user_id' => $first->user_id,
            'client' => $first->client_name,
            'personal' => $personal,
            'tokens' => $personal ? $tokens->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name ?: 'Token',
                'created_at' => Carbon::parse($t->created_at),
                'last_used_at' => $t->last_used_at ? Carbon::parse($t->last_used_at) : null,
                'expires_at' => Carbon::parse($t->expires_at),
            ])->sortByDesc('created_at')->values() : collect(),
            'connected_at' => Carbon::parse($tokens->min('created_at')),
            'last_used_at' => $tokens->max('last_used_at') ? Carbon::parse($tokens->max('last_used_at')) : null,
        ];
    }
}; ?>

@php
    $tz = config('ward.display_timezone');
    $when = fn (?\Illuminate\Support\Carbon $date) => $date ? $date->locale('pt_BR')->diffForHumans() : 'nunca';
@endphp

<section class="w-full space-y-10">
    <div>
        <flux:heading size="xl" level="1">Conexões MCP</flux:heading>
        <flux:subheading>As IAs que podem publicar e ler artifacts em seu nome. Revogue o que não usa mais.</flux:subheading>
    </div>

    {{-- Como conectar --}}
    <div class="space-y-4 rounded-xl border border-zinc-200 p-4 sm:p-5 dark:border-zinc-700">
        <div>
            <flux:heading>Conectar uma IA</flux:heading>
            <flux:text class="mt-1">Endereço do servidor MCP (HTTP). A IA abre esta página para você autorizar o acesso.</flux:text>
        </div>

        <div x-data="{ copied: false }" class="flex gap-2">
            <flux:input readonly size="sm" :value="$this->mcpUrl" class="flex-1 font-mono" aria-label="URL do servidor MCP" />
            <flux:button size="sm" icon="clipboard" data-copy="{{ $this->mcpUrl }}" x-on:click="navigator.clipboard.writeText($el.dataset.copy); copied = true; setTimeout(() => copied = false, 1500)">
                <span x-text="copied ? 'Copiado' : 'Copiar'">Copiar</span>
            </flux:button>
        </div>

        <div class="grid gap-4 text-sm md:grid-cols-2 [&>div]:min-w-0">
            <div>
                <p class="mb-1 font-medium">Claude Code</p>
                <pre class="overflow-x-auto rounded-lg border border-zinc-200 bg-zinc-50 p-3 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-900"><code>claude mcp add --transport http ward-artifacts {{ $this->mcpUrl }}</code></pre>
                <p class="mt-1 text-xs text-zinc-500">Depois rode <code>/mcp</code> no Claude Code e escolha autenticar.</p>
            </div>
            <div>
                <p class="mb-1 font-medium">Codex</p>
                <pre class="overflow-x-auto rounded-lg border border-zinc-200 bg-zinc-50 p-3 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-900"><code>codex mcp add ward-artifacts --url {{ $this->mcpUrl }}
codex mcp login ward-artifacts</code></pre>
            </div>
            <div>
                <p class="mb-1 font-medium">OpenCode (opencode.json)</p>
                <pre class="overflow-x-auto rounded-lg border border-zinc-200 bg-zinc-50 p-3 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-900"><code>{ "mcp": { "ward-artifacts": { "type": "remote", "url": "{{ $this->mcpUrl }}" } } }</code></pre>
                <p class="mt-1 text-xs text-zinc-500">Depois: <code>opencode mcp auth ward-artifacts</code></p>
            </div>
            <div>
                <p class="mb-1 font-medium">claude.ai</p>
                <p class="text-xs text-zinc-500">Configurações → Conectores → Adicionar conector personalizado, com a URL acima. O site precisa estar publicado na internet (HTTPS).</p>
            </div>
        </div>
    </div>

    {{-- Skills --}}
    <div class="space-y-2 rounded-xl border border-zinc-200 p-4 sm:p-5 dark:border-zinc-700">
        <flux:heading>Skills da Ward</flux:heading>
        <flux:text>
            Ensinam a IA a criar, publicar e editar artifacts. Não são públicas: instale a partir do clone
            do repositório <code>ward_artifacts</code>. O instalador também registra o MCP.
        </flux:text>
        <pre class="overflow-x-auto rounded-lg border border-zinc-200 bg-zinc-50 p-3 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-900"><code>./integrations/install.sh claude   # ou: codex, opencode, all</code></pre>
    </div>

    {{-- Conexões do usuário --}}
    <div class="space-y-3">
        <flux:heading>Minhas conexões</flux:heading>

        <div class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->connections->where('personal', false) as $connection)
                <div wire:key="conn-{{ $connection['client_id'] }}" class="flex items-center justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $connection['client'] }}</p>
                        <p class="text-xs text-zinc-500">
                            Conectado {{ $when($connection['connected_at']) }} · último uso {{ $when($connection['last_used_at']) }}
                        </p>
                    </div>
                    <flux:button size="sm" variant="ghost" class="shrink-0 text-red-600! dark:text-red-400!" wire:click="revoke('{{ $connection['client_id'] }}', null)" wire:confirm="Revogar o acesso de {{ $connection['client'] }}? A IA vai precisar autorizar de novo.">Revogar</flux:button>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-zinc-500">Nenhuma IA conectada por OAuth ainda.</p>
            @endforelse
        </div>
    </div>

    {{-- Tokens pessoais --}}
    <div class="space-y-3">
        <div>
            <flux:heading>Tokens de acesso pessoal</flux:heading>
            <flux:text class="mt-1">Para clientes MCP sem suporte a OAuth: envie no header <code>Authorization: Bearer &lt;token&gt;</code>. Valem 90 dias.</flux:text>
        </div>

        @if ($newToken)
            <flux:callout icon="key" color="amber">
                <flux:callout.heading>Copie o token agora: ele não aparece de novo</flux:callout.heading>
                <flux:callout.text>
                    <code class="mt-2 block max-h-28 overflow-auto break-all rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-900">{{ $newToken }}</code>
                </flux:callout.text>
                <x-slot name="controls">
                    <flux:button icon="x-mark" variant="ghost" size="sm" wire:click="$set('newToken', null)" />
                </x-slot>
            </flux:callout>
        @endif

        <form wire:submit="createToken" class="flex max-w-md items-start gap-2">
            <flux:input wire:model="tokenName" size="sm" placeholder="Nome (ex.: notebook, CI)" class="flex-1" />
            <flux:button type="submit" size="sm">Criar token</flux:button>
        </form>

        @php($personal = $this->connections->where('personal', true)->flatMap(fn ($c) => $c['tokens']))
        @if ($personal->isNotEmpty())
            <div class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($personal as $token)
                    <div wire:key="token-{{ $token['id'] }}" class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ $token['name'] }}</p>
                            <p class="text-xs text-zinc-500">
                                Criado {{ $when($token['created_at']) }} · último uso {{ $when($token['last_used_at']) }} · expira em {{ $token['expires_at']->timezone($tz)->format('d/m/Y') }}
                            </p>
                        </div>
                        <flux:button size="sm" variant="ghost" class="shrink-0 text-red-600! dark:text-red-400!" wire:click="revokeToken('{{ $token['id'] }}')" wire:confirm="Revogar o token {{ $token['name'] }}?">Revogar</flux:button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Admin --}}
    @if (auth()->user()->isAdmin())
        <div class="space-y-3">
            <flux:heading>Conexões de outros usuários</flux:heading>

            <div class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->allConnections as $connection)
                    <div wire:key="all-{{ $connection['user_id'] }}-{{ $connection['client_id'] }}" class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ '@'.$connection['username'] }} · {{ $connection['personal'] ? 'Token pessoal' : $connection['client'] }}</p>
                            <p class="text-xs text-zinc-500">
                                Conectado {{ $when($connection['connected_at']) }} · último uso {{ $when($connection['last_used_at']) }}
                            </p>
                        </div>
                        <flux:button size="sm" variant="ghost" class="shrink-0 text-red-600! dark:text-red-400!" wire:click="revoke('{{ $connection['client_id'] }}', {{ $connection['user_id'] }})" wire:confirm="Revogar esta conexão de {{ '@'.$connection['username'] }}?">Revogar</flux:button>
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-zinc-500">Nenhuma outra conexão ativa.</p>
                @endforelse
            </div>
        </div>
    @endif
</section>
