<?php

namespace App\Mcp\Tools;

use App\Designs\DesignSystems;
use App\Models\DesignBoard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_design_system')]
#[Description('Mostra um design system: título, descrição, documentação, tokens (tema) e os componentes (nome, descrição, tags, tamanho). O HTML de um componente: get_artboard_source com o id do design system. Para usar num design: import_component (link = muda junto; copy = versão adaptada) ou <ward-include ds="usuario/projeto" name="Componente"></ward-include> no HTML.')]
#[IsReadOnly]
class GetDesignSystemTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design_system' => $schema->string()->description('"usuario/projeto", id ou URL do design system.')->required(),
            'query' => $schema->string()->description('Filtra os componentes por nome, descrição ou tag (opcional).'),
        ];
    }

    public function handle(Request $request, DesignSystems $systems): Response|ResponseFactory
    {
        $system = $systems->find($request->get('design_system'));

        if (! $system || $this->user($request)->cannot('view', $system)) {
            return Response::error('Design system não encontrado. Veja list_design_systems.');
        }

        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim((string) $request->get('query')))) ?: []);
        $components = $system->orderedBoards()->get()
            ->filter(fn (DesignBoard $board) => $words === [] || collect($words)->every(fn ($word) => str_contains(mb_strtolower("{$board->name} {$board->title} {$board->description} {$board->tags}"), $word)))
            ->map(fn (DesignBoard $board) => [
                'name' => $board->name,
                'title' => $board->title,
                'description' => $board->description,
                'tags' => $board->tags,
                'kind' => $board->kind->value,
                'w' => $board->w,
                'h' => $board->h,
                'revision' => $board->revision,
                'uses' => $board->includes ?? [],
            ])->values();

        $ref = $systems->ref($system);
        $lines = $components->map(fn ($c) => "- {$c['name']} ({$c['w']}×{$c['h']})".($c['title'] && $c['title'] !== $c['name'] ? " \"{$c['title']}\"" : '').($c['description'] ? ": {$c['description']}" : '').($c['tags'] ? " [{$c['tags']}]" : ''));

        return Response::make(Response::text(
            "Design system {$ref} — \"{$system->title}\" (id {$system->uuid}). {$system->url()}\n".
            ($system->description ? "{$system->description}\n" : '').
            ($system->design_tokens ? "Tokens: {$system->design_tokens}\n" : "Sem tokens.\n").
            "Componentes ({$components->count()}):\n".($lines->isEmpty() ? 'nenhum ainda' : $lines->implode("\n"))."\n".
            ($system->docs ? "\nDocumentação:\n".Str::limit($system->docs, 12000) : "\nSem documentação ainda.")
        ))->withStructuredContent([
            'id' => $system->uuid,
            'ref' => $ref,
            'title' => $system->title,
            'description' => $system->description,
            'docs' => $system->docs,
            'tokens' => $system->design_tokens,
            'url' => $system->url(),
            'components' => $components->all(),
        ]);
    }
}
