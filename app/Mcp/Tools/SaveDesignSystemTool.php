<?php

namespace App\Mcp\Tools;

use App\Actions\Designs\SaveDesignSystem;
use App\Designs\DesignSystems;
use App\Designs\InvalidDesign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('save_design_system')]
#[Description('Cria o design system de um projeto seu (um por projeto) ou atualiza título, descrição e documentação (markdown: princípios, quando usar cada componente, tom de voz). Ele é um design como outro: componentes são artboards (upsert_artboard com description e tags), o tema é set_design_tokens. Fica visível para todos os usuários logados.')]
class SaveDesignSystemTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Projeto (feature) dono do design system. Veja list_projects.')->required(),
            'title' => $schema->string()->description('Título (obrigatório ao criar).'),
            'description' => $schema->string()->description('Uma ou duas frases sobre o design system.'),
            'docs' => $schema->string()->description('Documentação completa em markdown (substitui a atual).'),
        ];
    }

    public function handle(Request $request, SaveDesignSystem $save, DesignSystems $systems): Response|ResponseFactory
    {
        $validated = $request->validate([
            'project' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'docs' => ['nullable', 'string'],
        ]);

        try {
            $system = $save->handle($this->user($request), $validated['project'], array_intersect_key($validated, array_flip(array_keys(array_filter($request->all(), fn ($value, $key) => in_array($key, ['title', 'description', 'docs'], true), ARRAY_FILTER_USE_BOTH)))));
        } catch (InvalidDesign $e) {
            return Response::error($e->getMessage());
        }

        $ref = $systems->ref($system);

        return Response::make(Response::text(
            "Design system {$ref} salvo: \"{$system->title}\" (id {$system->uuid}). {$system->url()}\n".
            'Componentes: upsert_artboard com este id (description e tags ajudam no filtro). Tema: set_design_tokens.'
        ))->withStructuredContent(['id' => $system->uuid, 'ref' => $ref, 'url' => $system->url()]);
    }
}
