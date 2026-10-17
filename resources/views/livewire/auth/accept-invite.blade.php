<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    #[Locked]
    public string $token = '';

    #[Locked, Url]
    public string $email = '';

    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    /**
     * Define a senha do convidado e já entra na conta.
     */
    public function acceptInvite(): void
    {
        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $accepted = null;

        $status = Password::broker('invites')->reset(
            $this->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use (&$accepted) {
                $user->forceFill([
                    'password' => Hash::make($this->password),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));

                $accepted = $user;
            }
        );

        if ($status !== Password::PasswordReset) {
            $this->addError('password', 'Este convite é inválido ou expirou. Peça um novo convite ao administrador.');

            return;
        }

        Auth::login($accepted);
        session()->regenerate();

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header title="Aceitar convite" description="Defina uma senha para entrar no Ward Artifacts" />

    <form wire:submit="acceptInvite" class="flex flex-col gap-6">
        <flux:input :value="$email" label="E-mail" type="email" readonly disabled />

        <flux:input
            wire:model="password"
            label="Senha"
            type="password"
            required
            autocomplete="new-password"
            placeholder="Senha"
        />

        <flux:input
            wire:model="password_confirmation"
            label="Confirmar senha"
            type="password"
            required
            autocomplete="new-password"
            placeholder="Confirmar senha"
        />

        <flux:button type="submit" variant="primary" class="w-full">Definir senha e entrar</flux:button>
    </form>
</div>
