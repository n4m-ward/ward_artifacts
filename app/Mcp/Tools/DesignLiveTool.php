<?php

namespace App\Mcp\Tools;

use App\Designs\DesignPresence;
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
use Laravel\Mcp\Server\Ui\Enums\Visibility;

#[Name('design_live')]
#[Description('Uso interno do canvas do Ward Design (MCP App): estado atual do canvas se a revisão mudou. Não chame diretamente; use get_design.')]
#[IsReadOnly]
#[RendersApp(DesignApp::class, visibility: [Visibility::App])]
class DesignLiveTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'rev' => $schema->integer()->description('Revisão que o app já mostra.'),
            'p' => $schema->integer()->description('Presença da IA que o app já mostra (seq).'),
        ];
    }

    public function handle(Request $request, DesignState $state, DesignPresence $presence): Response|ResponseFactory
    {
        $request->validate(['design' => ['required', 'string'], 'rev' => ['nullable', 'integer'], 'p' => ['nullable', 'integer']]);

        $design = $this->findDesign($request, $request->get('design'));
        if ($design instanceof Response) {
            return $design;
        }

        $presence->touchViewer($design);
        $current = $presence->current($design);

        if ($request->get('rev') !== null && (int) $request->get('rev') === $design->design_revision) {
            return Response::make(Response::text('Sem mudanças.'))->withStructuredContent(array_filter([
                'changed' => false,
                'presence' => $request->get('p') !== null && (int) $request->get('p') !== (int) ($current['seq'] ?? 0) ? $current : null,
            ], fn ($value) => $value !== null));
        }

        return Response::make(Response::text("Revisão {$design->design_revision}."))
            ->withStructuredContent(['changed' => true, 'app' => $state->appConfig($design, $request->user())]);
    }
}
