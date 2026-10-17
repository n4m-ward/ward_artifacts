<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $email = '';

    /**
     * Send a password reset link to the provided email address.
     */
    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($this->only('email'));

        session()->flash('status', 'Se existir uma conta com esse e-mail, enviamos um link para redefinir a senha.');
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header title="Esqueci minha senha" description="Informe o e-mail da sua conta para receber o link de redefinição." />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="sendPasswordResetLink" class="flex flex-col gap-6">
        <!-- Email Address -->
        <div class="grid gap-2">
            <flux:input wire:model="email" label="E-mail" type="email" name="email" required autofocus placeholder="voce@exemplo.com" />
        </div>

        <flux:button variant="primary" type="submit" class="w-full">Enviar link de redefinição</flux:button>
    </form>

    <div class="space-x-1 text-center text-sm text-zinc-400">
        Lembrou?
        <x-text-link href="{{ route('login') }}">Voltar para o login</x-text-link>
    </div>
</div>
