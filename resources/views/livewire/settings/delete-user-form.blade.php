<?php

use App\Enums\Role;
use App\Livewire\Actions\Logout;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ], attributes: ['password' => 'senha']);

        $user = Auth::user();

        if ($user->isAdmin() && User::where('role', Role::Admin)->count() === 1) {
            $this->addError('password', 'Você é o único admin. Promova outra pessoa a admin antes de apagar a sua conta.');

            return;
        }

        tap($user, $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="mt-10 space-y-6">
    <flux:separator variant="subtle" />

    <div class="relative mb-5">
        <flux:heading>Apagar conta</flux:heading>
        <flux:subheading>Remove a sua conta, todos os seus artifacts e as conexões MCP.</flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button variant="danger" size="sm">Apagar conta</flux:button>
    </flux:modal.trigger>

    <flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="deleteUser" class="space-y-6">
            <div>
                <flux:heading size="lg">Apagar a sua conta?</flux:heading>

                <flux:subheading>
                    A conta, todos os seus artifacts e as conexões MCP são apagados de vez. Digite sua senha para confirmar.
                </flux:subheading>
            </div>

            <flux:input wire:model="password" label="Senha" type="password" name="password" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit">Apagar conta</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
