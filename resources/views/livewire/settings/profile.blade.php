<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id)
            ],
        ]);

        $user->fill($validated)->save();

        $this->dispatch('profile-updated', name: $user->name);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Perfil" subheading="Seu nome e e-mail.">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" label="Nome" type="text" name="name" required autofocus autocomplete="name" />

            <flux:input wire:model="email" label="E-mail" type="email" name="email" required autocomplete="email" />

            <flux:input
                :value="'@'.auth()->user()->username"
                label="Username"
                description="Aparece na URL dos seus artifacts. Para trocar, fale com um admin."
                readonly
                disabled
            />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">Salvar</flux:button>

                <x-action-message class="me-3" on="profile-updated">
                    Salvo.
                </x-action-message>
            </div>
        </form>

        <livewire:settings.delete-user-form />
    </x-settings.layout>
</section>
