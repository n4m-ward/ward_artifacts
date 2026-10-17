@props(['artifact'])

@if ($artifact->isPublic())
    <flux:badge size="sm" color="green" icon="globe-alt" {{ $attributes }}>
        Público até {{ $artifact->public_until->timezone(config('ward.display_timezone'))->format('d/m H:i') }}
    </flux:badge>
@elseif ($artifact->visibility === \App\Enums\Visibility::Users)
    <flux:badge size="sm" color="sky" icon="users" {{ $attributes }}>Usuários logados</flux:badge>
@else
    <flux:badge size="sm" icon="lock-closed" {{ $attributes }}>
        Só {{ auth()->id() === $artifact->user_id ? 'eu' : '@'.$artifact->user->username }}
    </flux:badge>
@endif
