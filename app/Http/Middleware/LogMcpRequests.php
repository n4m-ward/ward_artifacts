<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Diagnóstico do MCP (canal "mcp"): qual cliente conectou e se ele anuncia
 * suporte a MCP Apps, que recurso ui:// pediu, que ferramenta chamou e os
 * demais métodos (tools/list, ping...).
 * Nunca registra argumentos nem conteúdo.
 */
class LogMcpRequests
{
    public const UI_EXTENSION = 'io.modelcontextprotocol/ui';

    public function handle(Request $request, Closure $next): Response
    {
        $method = $request->json('method');
        $user = $request->user()?->username;

        // Protocolo 2026-07-28: sem initialize; o cliente manda as capacidades
        // em cada pedido, em params._meta.
        $meta = (array) ($request->input('params._meta') ?? []);
        $perRequest = (array) ($meta['io.modelcontextprotocol/clientCapabilities'] ?? []);
        $context = array_filter([
            'user' => $user,
            'agent' => $request->userAgent(),
            'client' => ($meta['io.modelcontextprotocol/clientInfo'] ?? [])['name'] ?? null,
            'protocol' => $meta['io.modelcontextprotocol/protocolVersion'] ?? null,
            'mcp_apps' => $perRequest === [] ? null : array_key_exists(self::UI_EXTENSION, (array) ($perRequest['extensions'] ?? [])),
            'extensions' => $perRequest === [] ? null : array_keys((array) ($perRequest['extensions'] ?? [])),
        ], fn ($value) => $value !== null);

        match ($method) {
            'initialize' => Log::channel('mcp')->info('initialize', [
                'user' => $user,
                'client' => $request->json('params.clientInfo.name'),
                'agent' => $request->userAgent(),
                'version' => $request->json('params.clientInfo.version'),
                'protocol' => $request->json('params.protocolVersion'),
                'mcp_apps' => array_key_exists(self::UI_EXTENSION, (array) ($request->input('params.capabilities')['extensions'] ?? [])),
                'capabilities' => array_keys((array) $request->input('params.capabilities', [])),
                'extensions' => array_keys((array) ($request->input('params.capabilities')['extensions'] ?? [])),
            ]),
            'resources/read' => Log::channel('mcp')->info('resources/read', ['user' => $user, 'uri' => $request->json('params.uri')] + $context),
            'tools/call' => Log::channel('mcp')->info('tools/call', ['user' => $user, 'tool' => $request->json('params.name')] + $context),
            default => Log::channel('mcp')->info($method ?? $request->method(), $context),
        };

        return $next($request);
    }
}
