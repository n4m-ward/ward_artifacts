<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Diagnóstico do MCP (canal "mcp"): qual cliente conectou e se ele anuncia
 * suporte a MCP Apps, que recurso ui:// pediu e que ferramenta chamou.
 * Nunca registra argumentos nem conteúdo.
 */
class LogMcpRequests
{
    public const UI_EXTENSION = 'io.modelcontextprotocol/ui';

    public function handle(Request $request, Closure $next): Response
    {
        $method = $request->json('method');
        $user = $request->user()?->username;

        match ($method) {
            'initialize' => Log::channel('mcp')->info('initialize', [
                'user' => $user,
                'client' => $request->json('params.clientInfo.name'),
                'version' => $request->json('params.clientInfo.version'),
                'protocol' => $request->json('params.protocolVersion'),
                'mcp_apps' => array_key_exists(self::UI_EXTENSION, (array) ($request->input('params.capabilities')['extensions'] ?? [])),
                'capabilities' => array_keys((array) $request->input('params.capabilities', [])),
                'extensions' => array_keys((array) ($request->input('params.capabilities')['extensions'] ?? [])),
            ]),
            'resources/read' => Log::channel('mcp')->info('resources/read', ['user' => $user, 'uri' => $request->json('params.uri')]),
            'tools/call' => Log::channel('mcp')->info('tools/call', ['user' => $user, 'tool' => $request->json('params.name')]),
            default => null,
        };

        return $next($request);
    }
}
