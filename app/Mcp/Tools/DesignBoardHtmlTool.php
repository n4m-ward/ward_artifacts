<?php

namespace App\Mcp\Tools;

use App\Artifacts\ContentGateway;
use App\Designs\BoardRenderer;
use App\Designs\DesignStorage;
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

/**
 * O HTML de um artboard como o canvas mostra (tokens, componentes e o runtime), para o
 * MCP App desenhar por dentro quando o host do chat bloqueia o iframe do host de
 * conteúdo (a tela ficava branca). O "assets/…" aponta para o host de conteúdo.
 */
#[Name('design_board_html')]
#[Description('Uso interno do canvas do Ward Design (MCP App): HTML de um artboard para mostrar sem iframe externo. Não chame diretamente.')]
#[IsReadOnly]
#[RendersApp(DesignApp::class, visibility: [Visibility::App])]
class DesignBoardHtmlTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'board' => $schema->string()->description('Nome do artboard.')->required(),
        ];
    }

    public function handle(Request $request, BoardRenderer $renderer, DesignStorage $storage, ContentGateway $gateway): Response|ResponseFactory
    {
        $validated = $request->validate(['design' => ['required', 'string'], 'board' => ['required', 'string', 'max:60']]);

        $design = $this->findDesign($request, $validated['design']);
        if ($design instanceof Response) {
            return $design;
        }

        $board = $design->boards()->where('name', $validated['board'])->first();
        if (! $board || $board->storage_path === '') {
            return Response::error("Artboard não encontrado: \"{$validated['board']}\".");
        }

        $html = BoardRenderer::withRuntime($renderer->render($design, $board, $storage->html($board)));
        $base = preg_replace('#/[^/]*$#', '/', $gateway->boardUrl($board, 60));
        $html = BoardRenderer::withHeadMarkup($html, '<base href="'.e($base).'">');

        return Response::make(Response::text("Artboard \"{$board->name}\"."))->withStructuredContent([
            'board' => $board->name,
            'version' => $renderer->version($design, $board),
            'html' => $html,
        ]);
    }
}
