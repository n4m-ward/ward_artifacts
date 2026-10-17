<?php

namespace App\Mcp\Tools;

use App\Enums\Visibility;
use App\Models\Artifact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_artifacts')]
#[Description('Lista artifacts. "mine": os seus. "viewed": de outras pessoas que você já abriu. "all": todos (só admin). Filtre por título ou projeto.')]
#[IsReadOnly]
class ListArtifactsTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->enum(['mine', 'viewed', 'all'])->description('Padrão: mine.'),
            'search' => $schema->string()->description('Parte do título.'),
            'type' => $schema->string()->enum(['page', 'design'])->description('Só páginas ou só designs. Padrão: os dois.'),
            'project' => $schema->string()->description('Slug ou nome do projeto.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Padrão: 25.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'scope' => ['nullable', 'in:mine,viewed,all'],
            'search' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', 'in:page,design'],
            'project' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $this->user($request);
        $scope = $request->get('scope') ?: 'mine';

        if ($scope === 'all' && ! $user->isAdmin()) {
            return Response::error('Só admins podem listar todos os artifacts.');
        }

        $query = match ($scope) {
            'viewed' => $user->viewedArtifacts()
                ->where('artifacts.user_id', '!=', $user->id)
                ->when(! $user->isAdmin(), fn ($query) => $query->where(fn ($query) => $query
                    ->where('visibility', Visibility::Users)
                    ->orWhere('public_until', '>', now())))
                ->orderByPivot('last_viewed_at', 'desc'),
            'all' => Artifact::query()->latest('updated_at'),
            default => $user->artifacts()->latest('updated_at'),
        };

        $artifacts = $query
            ->with('user', 'project', 'currentVersion')
            ->when($request->get('search'), fn ($query, $search) => $query->where('title', 'like', "%{$search}%"))
            ->when($request->get('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->get('project'), fn ($query, $project) => $query->whereHas('project', fn ($query) => $query
                ->where('slug', str($project)->slug())->orWhere('name', $project)))
            ->limit((int) ($request->get('limit') ?: 25))
            ->get();

        $items = $artifacts->map(fn (Artifact $artifact) => $this->artifactData($artifact))->values()->all();

        $text = $items === []
            ? 'Nenhum artifact encontrado.'
            : collect($items)->map(fn ($a) => "- {$a['title']} ({$a['project']}, ".($a['type'] === 'design' ? 'design' : "v{$a['version']}").", {$a['visibility']}) {$a['url']}")->implode("\n");

        return Response::make(Response::text($text))->withStructuredContent(['artifacts' => $items]);
    }
}
