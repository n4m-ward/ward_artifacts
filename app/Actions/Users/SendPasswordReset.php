<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\Password;

/**
 * Um admin manda para alguém o link de redefinição de senha (o mesmo do
 * "Esqueci minha senha"). Devolve o link para o admin repassar se o
 * e-mail não chegar.
 */
class SendPasswordReset
{
    public function handle(User $user): string
    {
        $token = Password::broker()->createToken($user);

        $user->sendPasswordResetNotification($token);

        return route('password.reset', ['token' => $token, 'email' => $user->email]);
    }
}
