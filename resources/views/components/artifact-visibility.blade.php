@props(['artifact', 'compact' => false])

@php
    $ownerLabel = auth()->id() === $artifact->user_id ? 'Só eu' : 'Só @'.$artifact->user->username;

    [$color, $icon, $label, $title] = match (true) {
        $artifact->isPublic() => [
            'green', 'globe-alt',
            $compact ? 'Público' : 'Público até '.$artifact->public_until->timezone(config('ward.display_timezone'))->format('d/m H:i'),
            'Público até '.$artifact->public_until->timezone(config('ward.display_timezone'))->format('d/m/Y H:i'),
        ],
        $artifact->visibility === \App\Enums\Visibility::Users => ['sky', 'users', $compact ? 'Logados' : 'Usuários logados', 'Visível para usuários logados'],
        default => ['zinc', 'lock-closed', $ownerLabel, 'Visível só para o dono e admins'],
    };
@endphp

<flux:badge size="sm" :color="$color" :icon="$icon" title="{{ $title }}" {{ $attributes }}>{{ $label }}</flux:badge>
