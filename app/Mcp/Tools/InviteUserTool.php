<?php

namespace App\Mcp\Tools;

use App\Actions\Users\InviteUser;
use App\Enums\Role;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('invite_user')]
#[Description('Convida um novo usuário (só admins). A pessoa recebe um e-mail com o link para definir a senha; o link também volta aqui, válido por 7 dias.')]
class InviteUserTool extends WardTool
{
    public function shouldRegister(Request $request): bool
    {
        return $request->user()?->isAdmin() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Nome da pessoa.')->required(),
            'username' => $schema->string()->description('Username (vai na URL): minúsculas, números e hífens. Ex.: nam-ward.')->required(),
            'email' => $schema->string()->description('E-mail da pessoa.')->required(),
            'role' => $schema->string()->enum(array_column(Role::cases(), 'value'))->description('Padrão: player.'),
        ];
    }

    public function handle(Request $request, InviteUser $inviteUser): Response|ResponseFactory
    {
        $user = $this->user($request);

        if (! $user->can('create', $user::class)) {
            return Response::error('Só admins podem convidar usuários.');
        }

        $validated = Validator::validate(
            [...$request->all(), 'role' => $request->get('role') ?: Role::Player->value],
            InviteUser::rules(),
        );

        $invited = $inviteUser->handle($user, $validated);

        return Response::make(Response::text("Convite enviado para {$invited->user->email}. Link (7 dias): {$invited->inviteUrl}"))
            ->withStructuredContent([
                'username' => $invited->user->username,
                'email' => $invited->user->email,
                'role' => $invited->user->role->value,
                'invite_url' => $invited->inviteUrl,
            ]);
    }
}
