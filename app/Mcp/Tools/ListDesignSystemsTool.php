<?php

namespace App\Mcp\Tools;

use App\Designs\DesignSystems;
use App\Models\Artifact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_design_systems')]
#[Description('Lista os design systems do Ward Artifacts (um por projeto, visíveis para todos), com a referência "usuario/projeto", descrição e quantos componentes têm. Filtre por texto (nome, projeto, componente, tag). Antes de desenhar uma tela, veja se o projeto já tem design system e reaproveite.')]
#[IsReadOnly]
class ListDesignSystemsTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->description('Texto para filtrar (opcional).')];
    }

    public function handle(Request $request, DesignSystems $systems): Response|ResponseFactory
    {
        $list = $systems->search($request->get('query'))->map(fn (Artifact $system) => $systems->summary($system))->values();

        $text = $list->isEmpty()
            ? 'Nenhum design system encontrado. Crie um para o projeto com save_design_system.'
            : $list->map(fn ($s) => "- {$s['ref']}: \"{$s['title']}\" ({$s['components']} componente(s))".($s['description'] ? " — {$s['description']}" : ''))->implode("\n");

        return Response::make(Response::text($text))->withStructuredContent(['design_systems' => $list->all()]);
    }
}
