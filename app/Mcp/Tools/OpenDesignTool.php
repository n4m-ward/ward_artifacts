<?php

namespace App\Mcp\Tools;

use App\Designs\DesignState;
use App\Mcp\Apps\DesignApp;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('open_design')]
#[Description('Abre o canvas de um design dentro do chat (em clientes com MCP Apps, como o Claude): a pessoa vê os artboards ao vivo enquanto você trabalha, abre em tela cheia e pode pedir mudanças apontando um artboard. Chame depois de create_design ou quando a pessoa quiser ver o design. Em outros clientes, devolve só o link.')]
#[IsReadOnly]
#[RendersApp(DesignApp::class)]
class OpenDesignTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
        ];
    }

    public function handle(Request $request, DesignState $state): Response|ResponseFactory
    {
        $request->validate(['design' => ['required', 'string']]);

        $design = $this->findDesign($request, $request->get('design'));
        if ($design instanceof Response) {
            return $design;
        }

        $config = $state->appConfig($design);
        $count = count($config['state']['boards']);

        return Response::make(Response::text(
            "Canvas \"{$design->title}\" aberto no chat ({$count} artboard(s)). Ele atualiza sozinho a cada upsert_artboard. Link: {$design->url()}"
        ))->withStructuredContent(['design' => $design->uuid, 'url' => $design->url(), 'app' => $config]);
    }
}
