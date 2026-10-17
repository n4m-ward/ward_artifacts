<?php

namespace App\Enums;

/**
 * Visibilidade base de um artifact. O nível público é temporário e fica em
 * artifacts.public_until: quando expira, o artifact volta para este nível.
 */
enum Visibility: string
{
    case Owner = 'owner';
    case Users = 'users';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Só eu',
            self::Users => 'Usuários logados',
        };
    }
}
