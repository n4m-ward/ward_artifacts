<?php

namespace App\Mcp\Tools;

use App\Actions\Designs\ImportComponent;
use App\Designs\DesignSystems;
use App\Designs\InvalidDesign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('import_component')]
#[Description('Traz um componente de um design system para um design como artboard novo. mode "link": o artboard inclui o componente e muda junto com o original. mode "copy": cópia do HTML para criar uma versão adaptada (edite com upsert_artboard). O design passa a usar o tema do design system se ainda não usa nenhum. Dentro de uma tela, também dá para usar <ward-include ds="usuario/projeto" name="Componente"></ward-include>.')]
class ImportComponentTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Design de destino (id ou URL).')->required(),
            'design_system' => $schema->string()->description('"usuario/projeto", id ou URL do design system.')->required(),
            'component' => $schema->string()->description('Nome do componente.')->required(),
            'mode' => $schema->string()->enum(['link', 'copy'])->description('link (padrão) ou copy.'),
            'name' => $schema->string()->description('Nome do artboard novo (padrão: o do componente).'),
            'x' => $schema->integer(),
            'y' => $schema->integer(),
            'page' => $schema->string()->description('Página do design (key).'),
        ];
    }

    public function handle(Request $request, ImportComponent $import, DesignSystems $systems): Response|ResponseFactory
    {
        $validated = $request->validate([
            'design' => ['required', 'string'], 'design_system' => ['required', 'string'], 'component' => ['required', 'string'],
            'mode' => ['nullable', 'in:link,copy'], 'name' => ['nullable', 'string', 'max:60'],
            'x' => ['nullable', 'integer'], 'y' => ['nullable', 'integer'], 'page' => ['nullable', 'string', 'max:40'],
        ]);

        $design = $this->findDesign($request, $validated['design']);
        if ($design instanceof Response) {
            return $design;
        }

        if ($error = $this->cannotEdit($request, $design)) {
            return $error;
        }

        $system = $systems->find($validated['design_system']);
        if (! $system || $this->user($request)->cannot('view', $system)) {
            return Response::error('Design system não encontrado. Veja list_design_systems.');
        }

        try {
            $board = $import->handle($design, $this->user($request), $system, $validated['component'], $validated['mode'] ?? 'link',
                array_intersect_key($validated, array_flip(['name', 'x', 'y'])) + ($request->has('page') ? ['page' => $validated['page']] : []));
        } catch (InvalidDesign $e) {
            return Response::error($e->getMessage());
        }

        $mode = ($validated['mode'] ?? 'link') === 'link' ? 'vinculado (muda junto com o design system)' : 'como cópia (pode adaptar)';

        return Response::text("Componente \"{$validated['component']}\" importado {$mode} como artboard \"{$board->name}\" ({$board->w}×{$board->h}). Design: {$design->url()}");
    }
}
