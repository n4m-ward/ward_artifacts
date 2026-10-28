<?php

use App\Actions\Whiteboards\CreateWhiteboard;
use App\Models\Artifact;
use App\Whiteboards\InvalidWhiteboard;
use App\Whiteboards\WhiteboardTemplates;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Whiteboards (estilo Miro): os meus e os compartilhados comigo (convidado
 * como editor), com busca e a criação de um quadro novo (vazio ou de um modelo).
 */
new class extends Component {
    /** mine | shared */
    #[Url(except: 'mine')]
    public string $tab = 'mine';

    #[Url(except: '')]
    public string $search = '';

    public string $title = '';
    public string $project = '';
    public string $template = '';

    #[Computed]
    public function boards()
    {
        $user = Auth::user();

        $query = $this->tab === 'shared'
            ? Artifact::query()->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
            : $user->artifacts();

        return $query->where('type', 'whiteboard')
            ->with('user', 'project')
            ->withCount(['whiteboardElements' => fn ($query) => $query->where('is_deleted', false), 'members'])
            ->when($this->search, fn ($query, $search) => $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%'))
            ->latest('updated_at')
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function projects()
    {
        return Auth::user()->projects()->orderBy('name')->pluck('name');
    }

    public function create(CreateWhiteboard $create): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'project' => ['required', 'string', 'max:120'],
            'template' => ['nullable', Rule::in(array_keys(WhiteboardTemplates::NAMES))],
        ], attributes: ['title' => 'título', 'project' => 'projeto', 'template' => 'modelo']);

        try {
            $board = $create->handle(Auth::user(), $this->title, $this->project, $this->template ?: null);
        } catch (InvalidWhiteboard $e) {
            $this->addError('template', $e->getMessage());

            return;
        }

        $this->redirect($board->path());
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Whiteboards</flux:heading>
            <flux:subheading>Quadros para post-its, fluxogramas, mapas mentais e retrospectivas. Você, seus convidados e a IA editam ao vivo.</flux:subheading>
        </div>
        <flux:modal.trigger name="create-whiteboard">
            <flux:button variant="primary" icon="plus">Novo whiteboard</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <flux:radio.group wire:model.live="tab" variant="segmented" size="sm" aria-label="Quais quadros">
            <flux:radio value="mine" label="Meus" />
            <flux:radio value="shared" label="Compartilhados comigo" />
        </flux:radio.group>
        <div class="w-full max-w-sm">
            <flux:input wire:model.live.debounce.300ms="search" size="sm" icon="magnifying-glass" placeholder="Buscar pelo título" aria-label="Buscar whiteboards" />
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->boards as $board)
            <a href="{{ $board->path() }}" wire:key="wb-{{ $board->uuid }}"
               class="group flex flex-col gap-2 rounded-xl border border-zinc-200 p-4 transition hover:border-sky-400 hover:shadow-sm dark:border-zinc-700 dark:hover:border-sky-500">
                <div class="flex items-start justify-between gap-2">
                    <span class="font-medium group-hover:underline">{{ $board->title }}</span>
                    <x-artifact-visibility :artifact="$board" compact class="shrink-0" />
                </div>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    {{ $board->whiteboard_elements_count }} {{ $board->whiteboard_elements_count === 1 ? 'elemento' : 'elementos' }}
                    @if ($board->members_count > 0)
                        · {{ $board->members_count }} {{ $board->members_count === 1 ? 'convidado' : 'convidados' }}
                    @endif
                </p>
                <p class="mt-auto text-xs text-zinc-500 dark:text-zinc-400">
                    {{ '@'.$board->user->username }} · {{ $board->project->name }} · atualizado {{ $board->updated_at->locale('pt_BR')->diffForHumans() }}
                </p>
            </a>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700">
                @if ($search !== '')
                    Nenhum whiteboard com esse título.
                @elseif ($tab === 'shared')
                    Ninguém compartilhou um whiteboard com você ainda.
                @else
                    Nenhum whiteboard ainda. Crie o primeiro, ou peça para a IA (create_whiteboard).
                @endif
            </div>
        @endforelse
    </div>

    <flux:modal name="create-whiteboard" class="w-full max-w-lg">
        <form wire:submit="create" class="space-y-5">
            <div>
                <flux:heading size="lg">Novo whiteboard</flux:heading>
                <flux:subheading>Nasce visível só para você. Depois convide editores em Compartilhar.</flux:subheading>
            </div>

            <flux:input wire:model="title" label="Título" placeholder="ex.: Retro da sprint 12" required />
            <flux:input wire:model="project" label="Projeto" list="wb-projects" placeholder="ex.: Ward Café" required />
            <datalist id="wb-projects">
                @foreach ($this->projects as $name)
                    <option value="{{ $name }}"></option>
                @endforeach
            </datalist>
            <flux:select wire:model="template" label="Começar de">
                <flux:select.option value="">Quadro vazio</flux:select.option>
                @foreach (\App\Whiteboards\WhiteboardTemplates::NAMES as $key => $name)
                    <flux:select.option :value="$key">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Criar</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
