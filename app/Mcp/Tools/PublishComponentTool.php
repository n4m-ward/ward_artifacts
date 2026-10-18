<?php

namespace App\Mcp\Tools;

use App\Actions\Designs\PublishComponent;
use App\Designs\DesignSystems;
use App\Designs\InvalidDesign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('publish_component')]
#[Description('Leva um artboard de um design para um design system como componente (cria ou atualiza pelo nome), com as imagens que usa. Use para promover uma peça boa (card, botão, navbar) a componente reutilizável.')]
class PublishComponentTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Design de origem (id ou URL).')->required(),
            'board' => $schema->string()->description('Nome do artboard de origem.')->required(),
            'design_system' => $schema->string()->description('"usuario/projeto", id ou URL do design system de destino.')->required(),
            'name' => $schema->string()->description('Nome do componente (padrão: o do artboard).'),
            'description' => $schema->string()->description('O que é e quando usar.'),
            'tags' => $schema->string()->description('Tags separadas por vírgula.'),
        ];
    }

    public function handle(Request $request, PublishComponent $publish, DesignSystems $systems): Response|ResponseFactory
    {
        $validated = $request->validate([
            'design' => ['required', 'string'], 'board' => ['required', 'string'], 'design_system' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:60'], 'description' => ['nullable', 'string', 'max:2000'], 'tags' => ['nullable', 'string', 'max:200'],
        ]);

        $design = $this->findDesign($request, $validated['design']);
        if ($design instanceof Response) {
            return $design;
        }

        $board = $design->boards()->where('name', $validated['board'])->first();
        if (! $board) {
            return Response::error("Artboard não encontrado: \"{$validated['board']}\".");
        }

        $system = $systems->find($validated['design_system']);
        if (! $system) {
            return Response::error('Design system não encontrado. Veja list_design_systems.');
        }

        if ($error = $this->cannotEdit($request, $system)) {
            return $error;
        }

        try {
            $component = $publish->handle($design, $board, $system, $this->user($request), $validated['name'] ?? null, $validated['description'] ?? null, $validated['tags'] ?? null);
        } catch (InvalidDesign $e) {
            return Response::error($e->getMessage());
        }

        return Response::text("Componente \"{$component->name}\" salvo no design system {$systems->ref($system)} (revisão {$component->revision}).");
    }
}
