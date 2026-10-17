<?php

namespace App\Actions\Mcp;

use App\Models\User;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\PersonalAccessTokenResult;
use RuntimeException;

/**
 * Token fixo (Bearer) para clientes MCP que não fazem OAuth. Prefira OAuth:
 * este token vale 90 dias e é mostrado uma única vez.
 */
class CreatePersonalMcpToken
{
    public function __construct(private ClientRepository $clients) {}

    public function handle(User $user, string $name): PersonalAccessTokenResult
    {
        try {
            $this->clients->personalAccessClient('users');
        } catch (RuntimeException) {
            $this->clients->createPersonalAccessGrantClient(config('app.name'), 'users');
        }

        return $user->createToken($name, [Registrar::OAUTH_SCOPE]);
    }
}
