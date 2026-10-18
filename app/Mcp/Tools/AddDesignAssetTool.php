<?php

namespace App\Mcp\Tools;

use App\Actions\Designs\AddDesignAsset;
use App\Designs\InvalidDesign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_design_asset')]
#[Description('Envia uma imagem (PNG, JPG, WebP, GIF, AVIF ou SVG, até 10 MB) para o design e devolve o caminho para usar nos artboards: src="assets/{nome}" ou url(assets/{nome}). A mesma imagem enviada de novo devolve o mesmo caminho. get_design lista as imagens que já existem (inclusive as enviadas pela pessoa no canvas).')]
class AddDesignAssetTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'filename' => $schema->string()->description('Nome do arquivo (ex.: "hero.jpg"), usado no caminho.')->required(),
            'data' => $schema->string()->description('Conteúdo em base64 (sem o prefixo data:).')->required(),
        ];
    }

    public function handle(Request $request, AddDesignAsset $assets): Response|ResponseFactory
    {
        $validated = $request->validate([
            'design' => ['required', 'string'],
            'filename' => ['required', 'string', 'max:160'],
            'data' => ['required', 'string'],
        ]);

        $design = $this->findDesign($request, $validated['design']);
        if ($design instanceof Response) {
            return $design;
        }

        if ($error = $this->cannotEdit($request, $design)) {
            return $error;
        }

        $bytes = base64_decode(preg_replace('/^data:[^,]*,/', '', $validated['data']), true);

        if ($bytes === false) {
            return Response::error('data não é base64 válido.');
        }

        try {
            $asset = $assets->handle($design, $this->user($request), $bytes, $validated['filename']);
        } catch (InvalidDesign $e) {
            return Response::error($e->getMessage());
        }

        return Response::make(Response::text(
            "Imagem salva: use \"{$asset->path()}\" no HTML do artboard".($asset->width ? " ({$asset->width}×{$asset->height})." : '.')
        ))->withStructuredContent(['design' => $design->uuid, 'asset' => $asset->toCanvas()]);
    }
}
