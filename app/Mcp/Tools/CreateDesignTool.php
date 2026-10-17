<?php

namespace App\Mcp\Tools;

use App\Actions\Designs\CreateDesign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_design')]
#[Description('Cria um DESIGN NOVO (Ward Design): um canvas vazio onde você coloca artboards (telas, posts, páginas) com upsert_artboard. Use para mockups, telas de app, landing pages, posts e peças visuais com várias variações. Nasce privado. Leia get_design_guide antes do primeiro artboard.')]
class CreateDesignTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(200)->description('Nome do design.')->required(),
            'project' => $schema->string()->max(120)->description('Feature/assunto da conversa atual (não o repositório). Veja list_projects.')->required(),
        ];
    }

    public function handle(Request $request, CreateDesign $create): Response|ResponseFactory
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'project' => ['required', 'string', 'max:120'],
        ]);

        $user = $this->user($request);
        $design = $create->handle($user, $validated['title'], $validated['project']);
        $preview = $this->previewUrl($user, $design);

        return Response::make(Response::text(
            "Design criado (privado): {$design->url()}\nid: {$design->uuid}\n\n".
            "Chame open_design para a pessoa acompanhar o canvas no chat (em clientes com MCP Apps). Depois crie os artboards com upsert_artboard (um por tela). As regras do formato estão em get_design_guide.\n\n".
            $this->previewHint($preview)
        ))->withStructuredContent($this->artifactData($design) + ['preview_url' => $preview]);
    }
}
