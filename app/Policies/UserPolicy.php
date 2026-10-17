<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Um admin não muda a própria role, para nunca ficar sem admin.
     */
    public function update(User $user, User $model): bool
    {
        return $user->isAdmin() && $user->isNot($model);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->isAdmin() && $user->isNot($model);
    }
}
