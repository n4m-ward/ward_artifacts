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
#[Description('Regras do formato dos artboards do Ward Design (fixed vs page, tamanhos comuns, organização do canvas, links entre telas) e dois esqueletos de HTML. Leia antes de criar o primeiro artboard.')]
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
            Response::text("Esqueleto kind=fixed (post 1080×1080):\n\n".file_get_contents(resource_path('design/fixed.html'))),
            Response::text("Esqueleto kind=page (página fluida):\n\n".file_get_contents(resource_path('design/page.html'))),
        ]);
    }
}
