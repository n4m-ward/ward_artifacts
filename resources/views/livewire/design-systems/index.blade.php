<?php

use App\Actions\Designs\SaveDesignSystem;
use App\Designs\DesignSystems;
use App\Designs\InvalidDesign;
use App\Models\Artifact;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Design systems de todos os projetos (visíveis para todos os usuários
 * logados), com busca e a criação do design system de um projeto seu.
 */
new class extends Component {
    #[Url(except: '')]
    public string $search = '';

    public string $project = '';
    public string $title = '';
    public string $description = '';

    #[Computed]
    public function systems()
    {
        $systems = app(DesignSystems::class);

        return $systems->search($this->search)->map(fn (Artifact $system) => $systems->summary($system) + ['path' => $system->path()]);
    }

    /**
     * Projetos do usuário que ainda não têm design system.
     */
    #[Computed]
    public function projects()
    {
        return Auth::user()->projects()->orderBy('name')->get()
            ->reject(fn ($project) => $project->artifacts()->where('design_system', true)->exists())
            ->pluck('name')->values();
    }

    public function create(SaveDesignSystem $save): void
    {
        $this->validate([
            'project' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['project' => 'projeto', 'title' => 'título', 'description' => 'descrição']);

        try {
            $system = $save->handle(Auth::user(), $this->project, ['title' => $this->title, 'description' => $this->description]);
        } catch (InvalidDesign $e) {
            $this->addError('project', $e->getMessage());

            return;
        }

        $this->redirect($system->path());
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Design systems</flux:heading>
            <flux:subheading>Um por projeto, com componentes, tema e documentação. Todos os usuários logados veem e reaproveitam.</flux:subheading>
        </div>
        <flux:modal.trigger name="create-design-system">
            <flux:button variant="primary" icon="plus">Novo design system</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="mb-4 max-w-sm">
        <flux:input wire:model.live.debounce.300ms="search" size="sm" icon="magnifying-glass" placeholder="Buscar por nome, projeto, componente ou tag" aria-label="Buscar design systems" />
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->systems as $system)
            <a href="{{ $system['path'] }}" wire:key="ds-{{ $system['id'] }}"
               class="group flex flex-col gap-2 rounded-xl border border-zinc-200 p-4 transition hover:border-sky-400 hover:shadow-sm dark:border-zinc-700 dark:hover:border-sky-500">
                <div class="flex items-start justify-between gap-2">
                    <span class="font-medium group-hover:underline">{{ $system['title'] }}</span>
                    <flux:badge size="sm" color="zinc">{{ $system['components'] }} {{ $system['components'] === 1 ? 'componente' : 'componentes' }}</flux:badge>
                </div>
                @if ($system['description'])
                    <p class="line-clamp-3 text-sm text-zinc-600 dark:text-zinc-300">{{ $system['description'] }}</p>
                @endif
                <p class="mt-auto text-xs text-zinc-500 dark:text-zinc-400">{{ '@'.$system['owner'] }} · {{ $system['project'] }} · <code>{{ $system['ref'] }}</code></p>
            </a>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700">
                {{ $search !== '' ? 'Nenhum design system com esse texto.' : 'Nenhum design system ainda. Crie o primeiro para um projeto seu.' }}
            </div>
        @endforelse
    </div>

    <flux:modal name="create-design-system" class="w-full max-w-lg">
        <form wire:submit="create" class="space-y-5">
            <div>
                <flux:heading size="lg">Novo design system</flux:heading>
                <flux:subheading>Um por projeto. Depois adicione componentes (artboards), o tema e a documentação, à mão ou pela IA.</flux:subheading>
            </div>

            <flux:input wire:model="project" label="Projeto" list="ds-projects" placeholder="ex.: Ward Café" required />
            <datalist id="ds-projects">
                @foreach ($this->projects as $name)
                    <option value="{{ $name }}"></option>
                @endforeach
            </datalist>
            <flux:input wire:model="title" label="Título" placeholder="ex.: Ward Café UI" required />
            <flux:textarea wire:model="description" label="Descrição" rows="3" placeholder="Para que serve, que produtos usam, princípios em uma frase." />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Criar</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
