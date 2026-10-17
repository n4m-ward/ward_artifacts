<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_projects')]
#[Description('Lista os seus projetos (as features/assuntos que agrupam artifacts), para reaproveitar o mesmo nome ao publicar algo da mesma feature.')]
#[IsReadOnly]
class ListProjectsTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $projects = $this->user($request)->projects()
            ->withCount('artifacts')
            ->withMax('artifacts', 'updated_at')
            ->orderByDesc('artifacts_max_updated_at')
            ->get()
            ->map(fn ($project) => [
                'name' => $project->name,
                'slug' => $project->slug,
                'artifacts' => $project->artifacts_count,
            ])
            ->all();

        $text = $projects === []
            ? 'Você ainda não tem projetos. O primeiro nasce quando você publica um artifact.'
            : collect($projects)->map(fn ($p) => "- {$p['name']} ({$p['slug']}): {$p['artifacts']} artifact(s)")->implode("\n");

        return Response::make(Response::text($text))->withStructuredContent(['projects' => $projects]);
    }
}
