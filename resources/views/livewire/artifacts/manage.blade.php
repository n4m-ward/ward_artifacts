<?php

use App\Actions\Artifacts\RenameArtifact;
use App\Actions\Artifacts\SetArtifactVisibility;
use App\Enums\Visibility;
use App\Models\Artifact;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

/**
 * Selo de visibilidade + controles do dono/admin na barra do viewer.
 * É o único lugar do sistema onde um artifact pode ficar público.
 */
new class extends Component {
    public Artifact $artifact;

    public string $visibility = '';
    public string $publicHours = '24';
    public string $title = '';

    public function mount(): void
    {
        $this->visibility = $this->artifact->visibility->value;
        $this->title = $this->artifact->title;
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('update', $this->artifact) ?? false;
    }

    public function updatedVisibility(SetArtifactVisibility $action): void
    {
        $this->authorize('update', $this->artifact);

        $this->validate(['visibility' => ['required', Rule::enum(Visibility::class)]]);

        $action->base($this->artifact, Visibility::from($this->visibility));
    }

    public function makePublic(SetArtifactVisibility $action): void
    {
        $this->authorize('update', $this->artifact);

        $this->validate(
            ['publicHours' => ['required', Rule::in(array_map('strval', SetArtifactVisibility::publicDurations()))]],
            attributes: ['publicHours' => 'duração'],
        );

        $action->makePublic($this->artifact, (int) $this->publicHours);
    }

    public function endPublic(SetArtifactVisibility $action): void
    {
        $this->authorize('update', $this->artifact);

        $action->endPublic($this->artifact);
    }

    public function rename(RenameArtifact $action): void
    {
        $this->authorize('update', $this->artifact);

        $this->validate(['title' => ['required', 'string', 'max:200']], attributes: ['title' => 'título']);

        $action->handle($this->artifact, $this->title);

        $this->redirect($this->artifact->path());
    }
}; ?>

<div class="flex items-center gap-1.5">
    <x-artifact-visibility :artifact="$artifact" />

    @if ($this->canManage)
        <flux:modal.trigger name="share-artifact">
            <flux:button size="sm" variant="primary" icon="share">
                <span class="max-sm:hidden">Compartilhar</span>
            </flux:button>
        </flux:modal.trigger>

        <flux:modal.trigger name="rename-artifact">
            <flux:button size="sm" variant="ghost" icon="pencil-square" aria-label="Renomear" title="Renomear" />
        </flux:modal.trigger>

        <flux:modal name="share-artifact" class="w-full max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Compartilhar</flux:heading>
                    <flux:subheading>Todo artifact nasce visível só para você. Escolha aqui quem mais pode abrir.</flux:subheading>
                </div>

                <div x-data="{ copied: false }" class="flex gap-2">
                    <flux:input readonly :value="$artifact->url()" class="flex-1" aria-label="Link do artifact" />
                    <flux:button
                        icon="clipboard"
                        x-on:click="navigator.clipboard.writeText(@js($artifact->url())); copied = true; setTimeout(() => copied = false, 1500)"
                    >
                        <span x-text="copied ? 'Copiado' : 'Copiar'">Copiar</span>
                    </flux:button>
                </div>

                <flux:radio.group wire:model.live="visibility" label="Quem pode ver">
                    <flux:radio value="owner" label="Só eu" description="Só você (e os admins) abrem este artifact." />
                    <flux:radio value="users" label="Usuários logados" description="Qualquer pessoa com conta no Ward Artifacts e com o link." />
                </flux:radio.group>

                <flux:separator />

                <div class="space-y-3">
                    <flux:heading>Link público temporário</flux:heading>

                    @if ($artifact->isPublic())
                        <flux:callout icon="globe-alt" color="green">
                            <flux:callout.text>
                                Qualquer pessoa com o link vê este artifact até
                                <strong>{{ $artifact->public_until->timezone(config('ward.display_timezone'))->format('d/m/Y \à\s H:i') }}</strong>.
                                Depois volta a valer "{{ \App\Enums\Visibility::from($visibility)->label() }}".
                            </flux:callout.text>
                        </flux:callout>

                        <div class="flex flex-wrap items-end gap-2">
                            <flux:select wire:model="publicHours" label="Estender a partir de agora por" class="max-w-48">
                                @foreach (\App\Actions\Artifacts\SetArtifactVisibility::publicDurations() as $hours)
                                    <flux:select.option :value="$hours">{{ \App\Actions\Artifacts\SetArtifactVisibility::durationLabel($hours) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:button wire:click="makePublic">Estender</flux:button>
                            <flux:button variant="danger" wire:click="endPublic">Encerrar agora</flux:button>
                        </div>
                    @else
                        <flux:text>Abre para qualquer pessoa, mesmo sem conta, por um tempo limitado. Nunca é para sempre.</flux:text>

                        <div class="flex flex-wrap items-end gap-2">
                            <flux:select wire:model="publicHours" label="Duração" class="max-w-48">
                                @foreach (\App\Actions\Artifacts\SetArtifactVisibility::publicDurations() as $hours)
                                    <flux:select.option :value="$hours">{{ \App\Actions\Artifacts\SetArtifactVisibility::durationLabel($hours) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:button icon="globe-alt" wire:click="makePublic">Tornar público</flux:button>
                        </div>
                    @endif

                    <flux:error name="publicHours" />
                </div>
            </div>
        </flux:modal>

        <flux:modal name="rename-artifact" class="w-full max-w-md">
            <form wire:submit="rename" class="space-y-6">
                <div>
                    <flux:heading size="lg">Renomear artifact</flux:heading>
                    <flux:subheading>O endereço muda junto, e o link antigo continua funcionando.</flux:subheading>
                </div>

                <flux:input wire:model="title" label="Título" required maxlength="200" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Salvar</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
