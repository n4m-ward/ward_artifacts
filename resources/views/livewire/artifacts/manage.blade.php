<?php

use App\Actions\Artifacts\RenameArtifact;
use App\Actions\Artifacts\SetArtifactVisibility;
use App\Actions\Whiteboards\WhiteboardEditors;
use App\Whiteboards\InvalidWhiteboard;
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

    public string $contentUrl = '';

    public string $visibility = '';
    public string $publicHours = '24';
    public string $title = '';
    public string $invite = '';

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

        if ($this->artifact->isDesignSystem() && $this->visibility === Visibility::Owner->value) {
            $this->visibility = Visibility::Users->value;

            return;
        }

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

    /**
     * Editores convidados (só whiteboards).
     */
    #[Computed]
    public function members()
    {
        return $this->artifact->isWhiteboard()
            ? $this->artifact->members()->with('user')->orderBy('id')->get()
            : collect();
    }

    public function inviteEditor(WhiteboardEditors $editors): void
    {
        $this->authorize('update', $this->artifact);
        $this->validate(['invite' => ['required', 'string', 'max:200']], attributes: ['invite' => 'username ou e-mail']);

        try {
            $editors->invite($this->artifact, auth()->user(), $this->invite);
        } catch (InvalidWhiteboard $e) {
            $this->addError('invite', $e->getMessage());

            return;
        }

        $this->invite = '';
        unset($this->members);
    }

    public function removeEditor(WhiteboardEditors $editors, int $userId): void
    {
        $this->authorize('update', $this->artifact);
        $editors->remove($this->artifact, $userId);
        unset($this->members);
    }

    public function rename(RenameArtifact $action): void
    {
        $this->authorize('update', $this->artifact);

        $this->validate(['title' => ['required', 'string', 'max:200']], attributes: ['title' => 'título']);

        $action->handle($this->artifact, $this->title);

        $this->redirect($this->artifact->path());
    }
}; ?>

<div class="flex shrink-0 items-center gap-1">
    @if ($this->canManage)
        <x-artifact-visibility
            :artifact="$artifact"
            compact
            as="button"
            icon:trailing="chevron-down"
            x-on:click="$flux.modal('share-artifact').show()"
            aria-label="Compartilhar: escolher quem pode ver"
        />
    @else
        <x-artifact-visibility :artifact="$artifact" compact />
    @endif

    <flux:dropdown position="bottom" align="end">
        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Mais ações" />

        <flux:menu>
            @if ($this->canManage)
                <flux:menu.item icon="share" x-on:click="$flux.modal('share-artifact').show()">Compartilhar</flux:menu.item>
                <flux:menu.item icon="pencil-square" x-on:click="$flux.modal('rename-artifact').show()">Renomear</flux:menu.item>
                <flux:menu.separator />
            @endif
            @if ($contentUrl)
                <flux:menu.item icon="arrow-top-right-on-square" :href="$contentUrl" target="_blank" rel="noopener noreferrer">Abrir HTML puro</flux:menu.item>
            @endif
            <flux:menu.item icon="arrow-down-tray" :href="route('artifacts.download', $artifact)">{{ $artifact->isWhiteboard() ? 'Baixar quadro (.excalidraw)' : ($artifact->isDesign() ? 'Baixar design (.zip)' : 'Baixar código') }}</flux:menu.item>
        </flux:menu>
    </flux:dropdown>

    @if ($this->canManage)
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
                        data-copy="{{ $artifact->url() }}"
                        x-on:click="navigator.clipboard.writeText($el.dataset.copy); copied = true; setTimeout(() => copied = false, 1500)"
                    >
                        <span x-text="copied ? 'Copiado' : 'Copiar'">Copiar</span>
                    </flux:button>
                </div>

                @if ($artifact->isDesignSystem())
                    <flux:callout icon="swatch">
                        <flux:callout.text>Design system: sempre visível para todos os usuários logados do Ward Artifacts. Para quem não tem conta, use o link público temporário abaixo.</flux:callout.text>
                    </flux:callout>
                @else
                    <flux:radio.group wire:model.live="visibility" label="Quem pode ver">
                        <flux:radio value="owner" label="Só eu" description="Só você (e os admins) abrem este artifact." />
                        <flux:radio value="users" label="Usuários logados" description="Qualquer pessoa com conta no Ward Artifacts e com o link." />
                    </flux:radio.group>
                @endif

                @if ($artifact->isWhiteboard())
                    <flux:separator />

                    <div class="space-y-3">
                        <div>
                            <flux:heading>Editores convidados</flux:heading>
                            <flux:text>Podem desenhar, mover e apagar no quadro (e a IA conectada na conta deles também). Veem o quadro mesmo com "Só eu".</flux:text>
                        </div>

                        <form wire:submit="inviteEditor" class="flex items-start gap-2">
                            <div class="flex-1">
                                <flux:input wire:model="invite" placeholder="username ou e-mail" aria-label="Username ou e-mail de quem convidar" />
                                <flux:error name="invite" />
                            </div>
                            <flux:button type="submit" icon="user-plus">Convidar</flux:button>
                        </form>

                        @if ($this->members->isNotEmpty())
                            <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                                @foreach ($this->members as $member)
                                    <li wire:key="member-{{ $member->id }}" class="flex items-center gap-3 px-3 py-2 text-sm">
                                        <span class="min-w-0 flex-1 truncate">{{ $member->user->name }} <span class="text-zinc-500">{{ '@'.$member->user->username }}</span></span>
                                        <flux:badge size="sm" color="zinc">editor</flux:badge>
                                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeEditor({{ $member->user_id }})" aria-label="Remover {{ $member->user->name }}" />
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif

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
