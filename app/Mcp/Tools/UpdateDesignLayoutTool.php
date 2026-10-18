<?php

namespace App\Mcp\Tools;

use App\Actions\Designs\UpdateDesignLayout;
use App\Designs\InvalidDesign;
use App\Models\DesignNote;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_layout')]
#[Description('Organiza o canvas de um design em lote: move/redimensiona/renomeia artboards e cria, edita ou apaga notas (títulos sobre fileiras e notas adesivas). Só muda o que vier na lista.')]
class UpdateDesignLayoutTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'boards' => $schema->array()->items($schema->object([
                'name' => $schema->string()->description('Nome atual do artboard.')->required(),
                'new_name' => $schema->string()->description('Novo nome (opcional).'),
                'title' => $schema->string(),
                'x' => $schema->integer(),
                'y' => $schema->integer(),
                'w' => $schema->integer(),
                'h' => $schema->integer(),
                'interactive' => $schema->boolean(),
            ]))->description('Artboards a mudar.'),
            'notes' => $schema->array()->items($schema->object([
                'key' => $schema->string()->description('Identificador da nota (letras, números, _ ou -).')->required(),
                'kind' => $schema->string()->enum(DesignNote::KINDS)->description('title: título grande sobre uma fileira. sticky: nota adesiva.'),
                'text' => $schema->string(),
                'x' => $schema->integer(),
                'y' => $schema->integer(),
                'w' => $schema->integer()->description('Largura (sticky) ou largura máxima (title).'),
                'color' => $schema->string()->enum(DesignNote::COLORS),
                'size' => $schema->string()->enum(DesignNote::SIZES)->description('Tamanho do texto (padrão: m na adesiva; o título é sempre grande).'),
                'bold' => $schema->boolean(),
                'delete' => $schema->boolean()->description('true para apagar a nota.'),
            ]))->description('Notas a criar, mudar ou apagar.'),
        ];
    }

    public function handle(Request $request, UpdateDesignLayout $update): Response|ResponseFactory
    {
        $validated = $request->validate([
            'design' => ['required', 'string'],
            'boards' => ['nullable', 'array', 'max:200'],
            'boards.*' => ['array'],
            'notes' => ['nullable', 'array', 'max:200'],
            'notes.*' => ['array'],
        ]);

        $design = $this->findDesign($request, $validated['design']);
        if ($design instanceof Response) {
            return $design;
        }

        if ($error = $this->cannotEdit($request, $design)) {
            return $error;
        }

        try {
            $update->handle($design, $validated['boards'] ?? [], $validated['notes'] ?? []);
        } catch (InvalidDesign $e) {
            return Response::error($e->getMessage());
        }

        $design->refresh();

        return Response::make(Response::text("Layout atualizado (revisão {$design->design_revision}). Design: {$design->url()}"))
            ->withStructuredContent(['design' => $design->uuid, 'design_revision' => $design->design_revision]);
    }
}
