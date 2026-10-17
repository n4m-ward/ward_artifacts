<?php

use App\Actions\Users\InviteUser;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';

    public string $name = '';
    public string $username = '';
    public string $email = '';
    public string $role = 'player';

    public ?string $inviteUrl = null;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function users()
    {
        return User::query()
            ->with('inviter')
            ->when($this->search, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(20);
    }

    public function invite(InviteUser $inviteUser): void
    {
        $this->authorize('create', User::class);

        $validated = $this->validate(InviteUser::rules());

        $invited = $inviteUser->handle(Auth::user(), $validated);

        $this->inviteUrl = $invited->inviteUrl;
        $this->reset('name', 'username', 'email', 'role');
        $this->modal('invite-user')->close();
    }

    public function resendInvite(User $user, InviteUser $inviteUser): void
    {
        $this->authorize('update', $user);

        abort_unless($user->hasPendingInvite(), 422);

        $this->inviteUrl = $inviteUser->send($user);
    }

    public function changeRole(User $user, string $role): void
    {
        $this->authorize('update', $user);

        $user->update(['role' => Role::from($role)]);
    }

    public function deleteUser(User $user): void
    {
        $this->authorize('delete', $user);

        $user->delete();

        $this->modal("delete-user-{$user->id}")->close();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Usuários</flux:heading>
            <flux:subheading>O cadastro é só por convite. Convide pessoas e gerencie as roles.</flux:subheading>
        </div>

        <flux:modal.trigger name="invite-user">
            <flux:button variant="primary" size="sm" icon="user-plus">Convidar usuário</flux:button>
        </flux:modal.trigger>
    </div>

    @if ($inviteUrl)
        <flux:callout icon="envelope" class="mb-6">
            <flux:callout.heading>Convite enviado por e-mail</flux:callout.heading>
            <flux:callout.text>
                Se o e-mail não chegar, envie este link para a pessoa (vale por 7 dias):
                <code class="mt-2 block break-all rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-900">{{ $inviteUrl }}</code>
            </flux:callout.text>
            <x-slot name="controls">
                <flux:button icon="x-mark" variant="ghost" size="sm" wire:click="$set('inviteUrl', null)" />
            </x-slot>
        </flux:callout>
    @endif

    <div class="mb-4 sm:max-w-xs">
        <flux:input wire:model.live.debounce.300ms="search" size="sm" icon="magnifying-glass" placeholder="Buscar por nome, username ou e-mail" />
    </div>

    <div class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->users as $user)
            <div wire:key="user-{{ $user->id }}" class="flex items-center gap-3 px-4 py-3">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ $user->name }}</p>
                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                        {{ '@'.$user->username }} · {{ $user->email }}
                        @if ($user->inviter)
                            <span class="max-md:hidden">· convidado por {{ $user->inviter->name }}</span>
                        @endif
                    </p>
                </div>

                @if ($user->hasPendingInvite())
                    <flux:badge size="sm" color="amber" class="shrink-0">Convite pendente</flux:badge>
                @endif

                <flux:badge size="sm" :color="$user->isAdmin() ? 'sky' : 'zinc'" class="shrink-0">{{ $user->role->label() }}</flux:badge>

                @canany(['update', 'delete'], $user)
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Ações de {{ $user->name }}" />
                        <flux:menu>
                            @can('update', $user)
                                @if ($user->isAdmin())
                                    <flux:menu.item icon="user" wire:click="changeRole({{ $user->id }}, 'player')">Tornar player</flux:menu.item>
                                @else
                                    <flux:menu.item icon="shield-check" wire:click="changeRole({{ $user->id }}, 'admin')">Tornar admin</flux:menu.item>
                                @endif
                                @if ($user->hasPendingInvite())
                                    <flux:menu.item icon="arrow-path" wire:click="resendInvite({{ $user->id }})">Reenviar convite</flux:menu.item>
                                @endif
                            @endcan
                            @can('delete', $user)
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" x-on:click="$flux.modal('delete-user-{{ $user->id }}').show()">Remover</flux:menu.item>
                            @endcan
                        </flux:menu>
                    </flux:dropdown>
                @else
                    <span class="w-8 shrink-0"></span>
                @endcanany

                @can('delete', $user)
                    <flux:modal :name="'delete-user-'.$user->id" class="w-full max-w-md">
                        <div class="space-y-6">
                            <div>
                                <flux:heading size="lg">Remover {{ $user->name }}?</flux:heading>
                                <flux:subheading>A conta, os artifacts e as conexões MCP dessa pessoa são apagados. Isso não pode ser desfeito.</flux:subheading>
                            </div>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost">Cancelar</flux:button>
                                </flux:modal.close>
                                <flux:button variant="danger" wire:click="deleteUser({{ $user->id }})">Remover</flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endcan
            </div>
        @empty
            <p class="px-4 py-10 text-center text-sm text-zinc-500">Nenhum usuário encontrado.</p>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $this->users->links() }}
    </div>

    <flux:modal name="invite-user" class="w-full max-w-md">
        <form wire:submit="invite" class="space-y-6">
            <div>
                <flux:heading size="lg">Convidar usuário</flux:heading>
                <flux:subheading>A pessoa recebe um e-mail com o link para definir a senha.</flux:subheading>
            </div>

            <flux:input wire:model="name" label="Nome" required />
            <flux:input wire:model="username" label="Username" description="Aparece na URL dos artifacts. Use letras minúsculas, números e hífens." required />
            <flux:input wire:model="email" label="E-mail" type="email" required />

            <flux:select wire:model="role" label="Role">
                @foreach (\App\Enums\Role::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Enviar convite</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
