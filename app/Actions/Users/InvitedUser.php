<?php

namespace App\Actions\Users;

use App\Models\User;

final readonly class InvitedUser
{
    public function __construct(
        public User $user,
        public string $inviteUrl,
    ) {}
}
