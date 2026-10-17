<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Player = 'player';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Player => 'Player',
        };
    }
}
