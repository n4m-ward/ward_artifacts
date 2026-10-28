<?php

namespace App\Mcp\Tools;

use App\Mcp\Apps\WhiteboardApp;
use App\Models\Artifact;
use App\Whiteboards\WhiteboardState;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('open_whiteboard')]
#[Description('Abre um whiteboard dentro do chat (em clientes com MCP Apps, como o Claude): a pessoa vê o quadro ao vivo enquanto você desenha, com o seu cursor, e pode pedir mudanças. Chame uma vez depois de create_whiteboard. Em outros clientes, devolve só o link (abra o preview_url no seu navegador).')]
#[IsReadOnly]
#[RendersApp(WhiteboardApp::class)]
class OpenWhiteboardTool extends WhiteboardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'whiteboard' => $schema->string()->description('Id (32 caracteres) ou URL do quadro.')->required(),
        ];
    }

    public function handle(Request $request, WhiteboardState $state): Response|ResponseFactory
    {
        $request->validate(['whiteboard' => ['required', 'string']]);

        return $this->withBoard($request, $request->get('whiteboard'), false, function (Artifact $board) use ($state) {
            // Sem o quadro na resposta: o app busca pelo ao vivo (whiteboard_live / URL assinada),
            // e clientes sem MCP Apps não recebem milhares de elementos no contexto.
            $config = $state->appConfig($board, withInitial: false);
            $count = $board->whiteboardElements()->where('is_deleted', false)->count();

            return Response::make(Response::text(
                "Quadro \"{$board->title}\" aberto no chat ({$count} elemento(s)). Ele atualiza sozinho a cada mudança. Link: {$board->url()}"
            ))->withStructuredContent(['whiteboard' => $board->uuid, 'url' => $board->url(), 'app' => $config]);
        });
    }
}
