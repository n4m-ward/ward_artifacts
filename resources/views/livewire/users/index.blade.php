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
            <flux:button variant="primary" icon="user-plus">Convidar usuário</flux:button>
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

    <div class="mb-4 max-w-sm">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Buscar por nome, username ou e-mail" />
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full min-w-[640px] text-left text-sm">
            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3 font-medium">Usuário</th>
                    <th class="px-4 py-3 font-medium">Role</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 font-medium">Convidado por</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->users as $user)
                    <tr wire:key="user-{{ $user->id }}">
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $user->name }}</div>
                            <div class="text-xs text-zinc-500">{{ '@'.$user->username }} · {{ $user->email }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @can('update', $user)
                                <flux:select size="sm" class="max-w-32" wire:change="changeRole({{ $user->id }}, $event.target.value)">
                                    @foreach (\App\Enums\Role::cases() as $case)
                                        <flux:select.option :value="$case->value" :selected="$user->role === $case">{{ $case->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @else
                                <flux:badge size="sm">{{ $user->role->label() }}</flux:badge>
                            @endcan
                        </td>
                        <td class="px-4 py-3">
                            @if ($user->hasPendingInvite())
                                <flux:badge size="sm" color="amber">Convite pendente</flux:badge>
                            @else
                                <flux:badge size="sm" color="green">Ativo</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-500">{{ $user->inviter?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                @if ($user->hasPendingInvite())
                                    @can('update', $user)
                                        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="resendInvite({{ $user->id }})">Reenviar</flux:button>
                                    @endcan
                                @endif

                                @can('delete', $user)
                                    <flux:modal.trigger :name="'delete-user-'.$user->id">
                                        <flux:button size="sm" variant="ghost" icon="trash" aria-label="Remover {{ $user->name }}" />
                                    </flux:modal.trigger>

                                    <flux:modal :name="'delete-user-'.$user->id" class="max-w-md">
                                        <div class="space-y-6">
                                            <div>
                                                <flux:heading size="lg">Remover {{ $user->name }}?</flux:heading>
                                                <flux:subheading>A conta deixa de existir e a pessoa perde o acesso. Isso não pode ser desfeito.</flux:subheading>
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
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-zinc-500">Nenhum usuário encontrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
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
