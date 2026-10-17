<?php

use App\Actions\Artifacts\DeleteArtifact;
use App\Actions\Artifacts\RenameArtifact;
use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    /** mine | viewed | all (admin) */
    #[Url(except: 'mine')]
    public string $tab = 'mine';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $owner = '';

    public ?string $renaming = null;
    public string $renameTitle = '';

    public ?string $deleting = null;

    public function mount(): void
    {
        if (! in_array($this->tab, $this->tabs(), true)) {
            $this->tab = 'mine';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'search', 'owner'], true)) {
            $this->resetPage();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, $this->tabs(), true) ? $tab : 'mine';
        $this->owner = '';
        $this->resetPage();
    }

    /**
     * @return list<string>
     */
    public function tabs(): array
    {
        return Auth::user()->isAdmin() ? ['mine', 'viewed', 'all'] : ['mine', 'viewed'];
    }

    #[Computed]
    public function artifacts()
    {
        $user = Auth::user();

        $query = match ($this->tab) {
            'viewed' => $user->viewedArtifacts()
                ->where('artifacts.user_id', '!=', $user->id)
                // Some da lista se o dono fechou o acesso depois.
                ->when(! $user->isAdmin(), fn ($query) => $query->where(fn ($query) => $query
                    ->where('visibility', Visibility::Users)
                    ->orWhere('public_until', '>', now())))
                ->orderByPivot('last_viewed_at', 'desc'),
            'all' => Artifact::query()
                ->when($this->owner, fn ($query, $owner) => $query->whereRelation('user', 'username', $owner))
                ->latest('updated_at'),
            default => $user->artifacts()->latest('updated_at'),
        };

        return $query
            ->with('user', 'project', 'currentVersion')
            ->when($this->search, fn ($query, $search) => $query->where('title', 'like', "%{$search}%"))
            ->paginate(20);
    }

    #[Computed]
    public function owners()
    {
        return User::whereHas('artifacts')->orderBy('username')->pluck('username');
    }

    public function startRename(string $uuid): void
    {
        $artifact = $this->find($uuid);
        $this->authorize('update', $artifact);

        $this->renaming = $uuid;
        $this->renameTitle = $artifact->title;
        $this->resetErrorBag();
        $this->modal('rename-artifact')->show();
    }

    public function rename(RenameArtifact $renameArtifact): void
    {
        $this->validate(['renameTitle' => ['required', 'string', 'max:200']], attributes: ['renameTitle' => 'título']);

        $artifact = $this->find((string) $this->renaming);
        $this->authorize('update', $artifact);

        $renameArtifact->handle($artifact, $this->renameTitle);

        $this->reset('renaming', 'renameTitle');
        $this->modal('rename-artifact')->close();
    }

    public function confirmDelete(string $uuid): void
    {
        $this->authorize('delete', $this->find($uuid));

        $this->deleting = $uuid;
        $this->modal('delete-artifact')->show();
    }

    public function delete(DeleteArtifact $deleteArtifact): void
    {
        $artifact = $this->find((string) $this->deleting);
        $this->authorize('delete', $artifact);

        $deleteArtifact->handle($artifact);

        $this->reset('deleting');
        $this->modal('delete-artifact')->close();
    }

    private function find(string $uuid): Artifact
    {
        return Artifact::where('uuid', $uuid)->firstOrFail();
    }
}; ?>

@php
    $tabLabels = ['mine' => 'Meus', 'viewed' => 'Acessados', 'all' => 'Todos'];
    $tz = config('ward.display_timezone');
@endphp

<section class="w-full">
    <div class="mb-6">
        <flux:heading size="xl" level="1">Artifacts</flux:heading>
        <flux:subheading>Os seus artifacts e os de outras pessoas que você já abriu.</flux:subheading>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800" role="tablist">
            @foreach ($this->tabs() as $key)
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    wire:click="setTab('{{ $key }}')"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-white shadow-sm dark:bg-zinc-700' => $tab === $key,
                        'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100' => $tab !== $key,
                    ])
                >{{ $tabLabels[$key] }}</button>
            @endforeach
        </div>

        <div class="min-w-48 flex-1 sm:max-w-xs">
            <flux:input wire:model.live.debounce.300ms="search" size="sm" icon="magnifying-glass" placeholder="Buscar por título" />
        </div>

        @if ($tab === 'all')
            <div class="w-full max-w-48">
                <flux:select wire:model.live="owner" size="sm" placeholder="Todos os usuários">
                    <flux:select.option value="">Todos os usuários</flux:select.option>
                    @foreach ($this->owners as $username)
                        <flux:select.option :value="$username">{{ '@'.$username }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif
    </div>

    <div class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->artifacts as $artifact)
            @php
                $when = $tab === 'viewed' ? \Illuminate\Support\Carbon::parse($artifact->pivot->last_viewed_at) : $artifact->updated_at;
                $whenLabel = ($tab === 'viewed' ? 'aberto ' : 'atualizado ').$when->locale('pt_BR')->diffForHumans();
            @endphp
            <div wire:key="artifact-{{ $artifact->uuid }}" class="flex items-center gap-3 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                <div class="min-w-0 flex-1">
                    <a href="{{ $artifact->path() }}" class="block truncate text-sm font-medium hover:underline">{{ $artifact->title }}</a>
                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                        @if ($tab !== 'mine')
                            {{ '@'.$artifact->user->username }} ·
                        @endif
                        {{ $artifact->project->name }}
                        · v{{ $artifact->currentVersion?->number ?? 0 }}
                        · {{ $artifact->currentVersion?->kind === \App\Enums\ArtifactKind::Bundle ? 'zip' : 'html' }}
                        · {{ \Illuminate\Support\Number::fileSize($artifact->size_bytes, precision: 1) }}
                        <span class="md:hidden" title="{{ $when->timezone($tz)->format('d/m/Y H:i') }}">· {{ $whenLabel }}</span>
                    </p>
                </div>

                <span class="hidden w-36 shrink-0 text-right text-xs text-zinc-500 md:block dark:text-zinc-400" title="{{ $when->timezone($tz)->format('d/m/Y H:i') }}">{{ $whenLabel }}</span>

                <x-artifact-visibility :artifact="$artifact" compact class="shrink-0" />

                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Ações de {{ $artifact->title }}" />
                    <flux:menu>
                        <flux:menu.item icon="eye" :href="$artifact->path()">Abrir</flux:menu.item>
                        <flux:menu.item icon="arrow-down-tray" :href="route('artifacts.download', $artifact)">Baixar código</flux:menu.item>
                        @can('update', $artifact)
                            <flux:menu.item icon="pencil-square" wire:click="startRename('{{ $artifact->uuid }}')">Renomear</flux:menu.item>
                        @endcan
                        @can('delete', $artifact)
                            <flux:menu.separator />
                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete('{{ $artifact->uuid }}')">Apagar</flux:menu.item>
                        @endcan
                    </flux:menu>
                </flux:dropdown>
            </div>
        @empty
            <p class="px-4 py-10 text-center text-sm text-zinc-500">
                @if ($search)
                    Nenhum artifact com "{{ $search }}".
                @elseif ($tab === 'mine')
                    Você ainda não publicou nenhum artifact. Eles chegam pelo MCP, a partir da sua IA.
                @elseif ($tab === 'viewed')
                    Artifacts de outras pessoas que você abrir aparecem aqui.
                @else
                    Nenhum artifact publicado ainda.
                @endif
            </p>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $this->artifacts->links() }}
    </div>

    <flux:modal name="rename-artifact" class="w-full max-w-md">
        <form wire:submit="rename" class="space-y-6">
            <div>
                <flux:heading size="lg">Renomear artifact</flux:heading>
                <flux:subheading>O endereço muda junto, e o link antigo continua funcionando.</flux:subheading>
            </div>

            <flux:input wire:model="renameTitle" label="Título" required maxlength="200" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Salvar</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-artifact" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Apagar este artifact?</flux:heading>
                <flux:subheading>O artifact e todas as versões são removidos e o link para de funcionar. Isso não pode ser desfeito.</flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">Apagar</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
