<?php

namespace App\Mcp\Tools;

use App\Designs\DesignStorage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_artboard_source')]
#[Description('Lê o HTML atual de um artboard para editar. Depois, publique com upsert_artboard usando o mesmo name.')]
#[IsReadOnly]
class GetArtboardSourceTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'name' => $schema->string()->description('Nome do artboard.')->required(),
        ];
    }

    public function handle(Request $request, DesignStorage $storage): Response|ResponseFactory
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

        return Response::make([
            Response::text("HTML do artboard \"{$board->name}\" ({$board->kind->value}, {$board->w}×{$board->h}, revisão {$board->revision}):"),
            Response::text($storage->html($board)),
        ]);
    }
}
