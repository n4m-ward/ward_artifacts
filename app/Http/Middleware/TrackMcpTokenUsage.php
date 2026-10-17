<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarda quando cada token foi usado pela última vez, para a tela de
 * Conexões. Grava no máximo uma vez por minuto por token.
 */
class TrackMcpTokenUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenId = $request->user()?->token()?->oauth_access_token_id;

        if ($tokenId) {
            DB::table('oauth_access_tokens')
                ->where('id', $tokenId)
                ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subMinute()))
                ->update(['last_used_at' => now()]);
        }

        return $next($request);
    }
}
