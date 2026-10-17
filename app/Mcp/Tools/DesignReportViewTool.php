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
use Laravel\Mcp\Server\Ui\Enums\Visibility;

#[Name('design_report_view')]
#[Description('Uso interno do canvas do Ward Design (MCP App): informa qual artboard a pessoa selecionou. Não chame diretamente; get_design já mostra a seleção.')]
#[RendersApp(DesignApp::class, visibility: [Visibility::App])]
class DesignReportViewTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'board' => $schema->string()->description('Nome do artboard selecionado; vazio quando nada está selecionado.'),
        ];
    }

    public function handle(Request $request, DesignState $state): Response|ResponseFactory
    {
        $request->validate(['design' => ['required', 'string'], 'board' => ['nullable', 'string', 'max:60']]);

        $design = $this->findDesign($request, $request->get('design'));
        if ($design instanceof Response) {
            return $design;
        }

        $board = filled($request->get('board')) ? (string) $request->get('board') : null;

        if ($board !== null && ! $design->boards()->where('name', $board)->exists()) {
            return Response::error("Artboard \"{$board}\" não existe neste design.");
        }

        $state->reportViewing($design, $this->user($request), $board);

        return Response::make(Response::text('Ok.'))->withStructuredContent(['board' => $board]);
    }
}
