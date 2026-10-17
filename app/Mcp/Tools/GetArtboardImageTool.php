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

#[Name('get_artboard_image')]
#[Description('Print de UM artboard, renderizado no servidor como aparece no canvas (fontes, fotos e CSS reais). Use depois de cada upsert_artboard para olhar a tela e só considerá-la pronta se estiver sem defeitos visuais. Para ver o conjunto, use get_design_image.')]
#[IsReadOnly]
class GetArtboardImageTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'name' => $schema->string()->description('Nome do artboard.')->required(),
        ];
    }

    public function handle(Request $request, DesignScreenshot $screenshots): Response|ResponseFactory
    {
        $request->validate(['design' => ['required', 'string'], 'name' => ['required', 'string']]);

        $design = $this->findDesign($request, $request->get('design'));
        if ($design instanceof Response) {
            return $design;
        }

        $board = $design->boards()->where('name', $request->get('name'))->first();

        if (! $board) {
            return Response::error("Artboard não encontrado: \"{$request->get('name')}\".");
        }

        try {
            $shot = $screenshots->board($board);
        } catch (ScreenshotFailed $e) {
            return Response::error(self::fallback($e));
        }

        return Response::make([
            Response::text(
                "Print do artboard \"{$board->name}\" (revisão {$board->revision}, {$board->w}×{$board->h} px, imagem {$shot->width}×{$shot->height}). ".
                'Confira: nada cortado ou escondido, texto sem quebra estranha, fotos e fontes carregadas, alinhamento, contraste, '.
                'hierarquia e consistência com os outros artboards. Corrija com upsert_artboard e olhe de novo.'
            ),
            Response::image($shot->jpeg, 'image/jpeg'),
        ]);
    }

    public static function fallback(ScreenshotFailed $e): string
    {
        return "Não consegui gerar o print pelo servidor: {$e->getMessage()} ".
            'Se você tem navegador, abra o canvas (preview_url de get_design) e tire o print por lá.';
    }
}
