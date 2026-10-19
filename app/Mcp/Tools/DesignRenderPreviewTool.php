<?php

namespace App\Mcp\Tools;

use App\Artifacts\ContentGateway;
use App\Designs\BoardRenderer;
use App\Mcp\Apps\DesignApp;
use App\Models\DesignBoard;
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
 * Prévia do artboard enquanto a IA escreve (MCP App): o HTML parcial com os
 * tokens do design, os componentes (<ward-include>) e as imagens "assets/…",
 * como ele vai aparecer no canvas. Sem isso, a prévia ficava branca.
 */
#[Name('design_render_preview')]
#[Description('Uso interno do canvas do Ward Design (MCP App): monta a prévia de um artboard em escrita. Não chame diretamente.')]
#[IsReadOnly]
#[RendersApp(DesignApp::class, visibility: [Visibility::App])]
class DesignRenderPreviewTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'name' => $schema->string()->description('Nome do artboard.')->required(),
            'html' => $schema->string()->description('HTML (parcial) do artboard.')->required(),
        ];
    }

    public function handle(Request $request, BoardRenderer $renderer, ContentGateway $gateway): Response|ResponseFactory
    {
        $validated = $request->validate([
            'design' => ['required', 'string'],
            'name' => ['required', 'string', 'max:60'],
            'html' => ['required', 'string', 'max:'.(int) config('ward.designs.max_board_bytes')],
        ]);

        $design = $this->findDesign($request, $validated['design']);
        if ($design instanceof Response) {
            return $design;
        }

        $html = $validated['html'];
        $board = $design->boards()->where('name', $validated['name'])->first()
            ?? new DesignBoard(['name' => $validated['name'], 'includes' => DesignBoard::includesIn($html) ?: null]);
        $board->includes = DesignBoard::includesIn($html) ?: null;

        $rendered = $renderer->render($design, $board, $html);

        // "assets/…" relativo a um artboard servido do host de conteúdo (as imagens são do design).
        $any = $board->exists ? $board : $design->boards()->first();
        if ($any) {
            $base = preg_replace('#/[^/]*$#', '/', $gateway->boardUrl($any, 30));
            $rendered = preg_replace('/<head(\s[^>]*)?>/i', '$0<base href="'.e($base).'">', $rendered, 1) ?? $rendered;
        }

        return Response::make(Response::text('Prévia montada.'))->withStructuredContent(['html' => $rendered]);
    }
}
