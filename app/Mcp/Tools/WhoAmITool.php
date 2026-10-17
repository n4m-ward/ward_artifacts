<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Number;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('whoami')]
#[Description('Mostra o usuário conectado, a role e os limites de envio de artifacts.')]
#[IsReadOnly]
#[IsIdempotent]
class WhoAmITool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user($request);

        $data = [
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role->value,
            'artifacts' => $user->artifacts()->count(),
            'max_artifact_size' => Number::fileSize((int) config('ward.artifacts.max_bytes')),
            'max_inline_html' => Number::fileSize((int) config('ward.artifacts.mcp_inline_max_bytes')),
            'panel_url' => route('dashboard'),
        ];

        return Response::make(Response::text("{$user->name} (@{$user->username}), role {$data['role']}."))
            ->withStructuredContent($data);
    }
}
