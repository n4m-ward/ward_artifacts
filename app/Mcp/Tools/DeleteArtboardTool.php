<?php

namespace App\Mcp\Tools;

use App\Actions\Designs\DeleteArtboard;
use App\Designs\InvalidDesign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_artboard')]
#[Description('Remove um artboard de um design. Só faça isso se o usuário pediu; o conteúdo só volta por uma versão salva.')]
#[IsDestructive]
class DeleteArtboardTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'name' => $schema->string()->description('Nome do artboard.')->required(),
        ];
    }

    public function handle(Request $request, DeleteArtboard $delete): Response|ResponseFactory
    {
        $request->validate(['design' => ['required', 'string'], 'name' => ['required', 'string']]);

        $design = $this->findDesign($request, $request->get('design'));
        if ($design instanceof Response) {
            return $design;
        }

        if ($error = $this->cannotEdit($request, $design)) {
            return $error;
        }

        try {
            $delete->handle($design, $request->get('name'));
        } catch (InvalidDesign $e) {
            return Response::error($e->getMessage());
        }

        return Response::text("Artboard \"{$request->get('name')}\" removido.");
    }
}
