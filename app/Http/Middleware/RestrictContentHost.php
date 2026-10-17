<?php

namespace App\Http\Middleware;

use App\Artifacts\ContentGateway;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O host de conteúdo aponta para o mesmo app, mas lá só existe /c/...:
 * login, painel e MCP nunca respondem na origem onde roda HTML de terceiros.
 */
class RestrictContentHost
{
    public function __construct(private ContentGateway $gateway) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->gateway->isIsolated()
            && $request->getHost() === $this->gateway->host()
            && ! $request->is('c/*')) {
            abort(404);
        }

        return $next($request);
    }
}
