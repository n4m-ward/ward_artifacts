<?php

namespace App\Mcp\Tools;

use App\Actions\Whiteboards\CreateWhiteboard;
use App\Whiteboards\InvalidWhiteboard;
use App\Whiteboards\WhiteboardTemplates;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_whiteboard')]
#[Description('Cria um WHITEBOARD NOVO (Ward Whiteboard, estilo Miro, com Excalidraw): um quadro infinito para post-its, fluxogramas, mapas mentais, retrospectivas e diagramas, que várias pessoas editam ao vivo. Nasce privado. Para telas e mockups visuais use create_design. Opcional: comece de um template.')]
class CreateWhiteboardTool extends WhiteboardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(200)->description('Nome do quadro.')->required(),
            'project' => $schema->string()->max(120)->description('Feature/assunto da conversa atual (não o repositório). Veja list_projects.')->required(),
            'template' => $schema->string()->enum(array_keys(WhiteboardTemplates::NAMES))->description('Modelo inicial (opcional): '.implode(', ', array_map(fn ($key, $name) => "{$key} = {$name}", array_keys(WhiteboardTemplates::NAMES), WhiteboardTemplates::NAMES)).'.'),
        ];
    }

    public function handle(Request $request, CreateWhiteboard $create): Response|ResponseFactory
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'project' => ['required', 'string', 'max:120'],
            'template' => ['nullable', 'string', 'in:'.implode(',', array_keys(WhiteboardTemplates::NAMES))],
        ]);

        $user = $this->user($request);

        try {
            $board = $create->handle($user, $validated['title'], $validated['project'], $validated['template'] ?? null);
        } catch (InvalidWhiteboard $e) {
            return Response::error($e->getMessage());
        }

        $preview = $this->previewUrl($user, $board);

        return Response::make(Response::text(
            "Whiteboard criado (privado): {$board->url()}\nid: {$board->uuid}\n\n".
            "Abra o preview_url no seu navegador para a pessoa ver você desenhando (ou entregue a URL). Desenhe com add_elements (post-its, formas, setas presas, frames) e create_diagram (nós + ligações com layout automático). Leia o quadro com get_whiteboard.\n\n".
            $this->previewHint($preview)
        ))->withStructuredContent($this->artifactData($board) + ['preview_url' => $preview]);
    }
}
