<?php

namespace App\Actions\Mcp;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoga os tokens (e refresh tokens) que um cliente MCP recebeu de um
 * usuário. O cliente precisa autorizar de novo para voltar a conectar.
 */
class RevokeMcpConnection
{
    public function forClient(User $user, string $clientId): int
    {
        return $this->revoke(DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->where('client_id', $clientId));
    }

    public function allFor(User $user): int
    {
        return $this->revoke(DB::table('oauth_access_tokens')->where('user_id', $user->id));
    }

    private function revoke($tokens): int
    {
        $ids = $tokens->where('revoked', false)->pluck('id');

        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $ids)->update(['revoked' => true]);

        return DB::table('oauth_access_tokens')->whereIn('id', $ids)->update(['revoked' => true]);
    }
}
