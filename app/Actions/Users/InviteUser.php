<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Cria um usuário convidado e envia o link para ele definir a senha.
 * Usado pela tela de usuários e, depois, pela ferramenta invite_user do MCP.
 */
class InviteUser
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'min:3', 'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(config('ward.reserved_usernames')),
                Rule::unique(User::class),
            ],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }

    /**
     * @param  array{name: string, username: string, email: string, role: string}  $data
     */
    public function handle(User $inviter, array $data): InvitedUser
    {
        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'role' => Role::from($data['role']),
            'password' => Str::random(64),
            'created_by' => $inviter->id,
        ]);

        return new InvitedUser($user, $this->send($user));
    }

    /**
     * Gera um novo token (invalidando o anterior) e envia o e-mail de convite.
     */
    public function send(User $user): string
    {
        $token = Password::broker('invites')->createToken($user);

        $url = route('invite.accept', ['token' => $token, 'email' => $user->email]);

        $user->notify(new UserInvited($url));

        return $url;
    }
}
