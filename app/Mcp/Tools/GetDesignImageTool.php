<?php

namespace App\Mcp\Tools;

use App\Designs\DesignScreenshot;
use App\Designs\ScreenshotFailed;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_design_image')]
#[Description('Print do canvas INTEIRO de um design (todos os artboards, títulos e notas, enquadrados como em "ajustar tudo"). Use para conferir o conjunto: consistência de fontes, cores e espaçamento entre as telas e a organização do canvas. Para os detalhes de uma tela, use get_artboard_image.')]
#[IsReadOnly]
class GetDesignImageTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
        ];
    }

    public function handle(Request $request, DesignScreenshot $screenshots): Response|ResponseFactory
    {
        $request->validate(['design' => ['required', 'string']]);

        $design = $this->findDesign($request, $request->get('design'));
        if ($design instanceof Response) {
            return $design;
        }

        try {
            $shot = $screenshots->design($design);
        } catch (ScreenshotFailed $e) {
            return Response::error(GetArtboardImageTool::fallback($e));
        }

        $count = $design->boards()->count();

        return Response::make([
            Response::text(
                "Print do canvas \"{$design->title}\" ({$count} artboard(s), revisão {$design->design_revision}, imagem {$shot->width}×{$shot->height}). ".
                'Confira o conjunto: a mesma direção visual em todas as telas, fileiras alinhadas e títulos no lugar. '.
                'Detalhes pequenos ficam ilegíveis aqui: para eles use get_artboard_image.'
            ),
            Response::image($shot->jpeg, 'image/jpeg'),
        ]);
    }
}
