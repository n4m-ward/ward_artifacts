<?php

namespace App\Mcp\Tools;

use App\Mcp\Apps\WhiteboardApp;
use App\Models\Artifact;
use App\Whiteboards\WhiteboardPresence;
use App\Whiteboards\WhiteboardState;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Ui\Enums\Visibility;

#[Name('whiteboard_live')]
#[Description('Uso interno do whiteboard no chat (MCP App): o que mudou no quadro desde o seq informado. Não chame diretamente; use get_whiteboard.')]
#[IsReadOnly]
#[RendersApp(WhiteboardApp::class, visibility: [Visibility::App])]
class WhiteboardLiveTool extends WhiteboardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'whiteboard' => $schema->string()->description('Id (32 caracteres) ou URL do quadro.')->required(),
            'since' => $schema->integer()->description('Seq que o app já mostra (0 = tudo).'),
            'p' => $schema->string()->description('Assinatura da presença que o app já mostra.'),
        ];
    }

    public function handle(Request $request, WhiteboardState $state, WhiteboardPresence $presence): Response|ResponseFactory
    {
        $request->validate(['whiteboard' => ['required', 'string'], 'since' => ['nullable', 'integer'], 'p' => ['nullable', 'string']]);

        return $this->withBoard($request, $request->get('whiteboard'), false, function (Artifact $board) use ($request, $state, $presence) {
            $presence->touchViewer($board);
            $since = (int) $request->get('since', 0);

            if ($since > 0 && $since === $board->whiteboard_seq) {
                $signature = $presence->signature($board);
                $data = $request->get('p') === $signature
                    ? ['changed' => false]
                    : ['changed' => false, 'unchanged' => true, 'seq' => $board->whiteboard_seq, 'people' => $presence->people($board), 'ai' => $presence->ai($board), 'presence' => $signature];

                return Response::make(Response::text('Sem mudanças.'))->withStructuredContent($data);
            }

            return Response::make(Response::text("Seq {$board->whiteboard_seq}."))
                ->withStructuredContent(['changed' => true] + $state->live($board, $since, null));
        });
    }
}
