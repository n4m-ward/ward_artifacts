<?php

namespace App\Mcp\Tools;

use App\Designs\DesignAppRequests;
use App\Mcp\Apps\DesignApp;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Ui\Enums\Visibility;

#[Name('design_app_request')]
#[Description('Uso interno do canvas do Ward Design (MCP App): ações do editor (salvar artboards e notas, comentários, imagens, design systems). Não chame diretamente.')]
#[RendersApp(DesignApp::class, visibility: [Visibility::App])]
class DesignAppRequestTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'method' => $schema->string()->enum(['GET', 'POST', 'PATCH', 'DELETE'])->required(),
            'path' => $schema->string()->description('Caminho da ação do editor, ex.: d/{id}/edit.')->required(),
            'body' => $schema->object()->description('Corpo JSON da ação; imagem em file: {name, type, base64}.'),
        ];
    }

    public function handle(Request $request, DesignAppRequests $requests): Response|ResponseFactory
    {
        $request->validate([
            'method' => ['required', 'string', 'in:GET,POST,PATCH,DELETE'],
            'path' => ['required', 'string', 'max:300'],
            'body' => ['nullable', 'array'],
        ]);

        $result = $requests->dispatch($request->user(), (string) $request->get('method'), (string) $request->get('path'), (array) ($request->get('body') ?? []));

        return Response::make(Response::text("HTTP {$result['status']}"))->withStructuredContent($result);
    }
}
