<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_design_guide')]
#[Description('Como fazer telas de alta fidelidade no Ward Design: direção visual (fontes, paleta), critérios de acabamento, conferência de cada artboard, regras do formato (fixed vs page, tamanhos, organização do canvas, links entre telas) e dois esqueletos de HTML. Leia antes de criar o primeiro artboard.')]
#[IsReadOnly]
#[IsIdempotent]
class GetDesignGuideTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return Response::make([
            Response::text(file_get_contents(resource_path('design/guide.md'))),
            Response::text("Esqueleto kind=fixed (celular 390×844; troque fontes e tokens pela direção do design):\n\n".file_get_contents(resource_path('design/fixed.html'))),
            Response::text("Esqueleto kind=page (página fluida; troque fontes e tokens pela direção do design):\n\n".file_get_contents(resource_path('design/page.html'))),
        ]);
    }
}
