<?php

namespace App\Designs;

use App\Models\Artifact;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Design systems: um design por projeto (artifacts.design_system), sempre
 * visível para quem está logado. A referência curta é "usuario/projeto".
 */
class DesignSystems
{
    /**
     * Aceita o id (32 hex), a URL do design system ou "usuario/projeto".
     */
    public function find(?string $reference): ?Artifact
    {
        $reference = trim((string) $reference);

        if ($reference === '') {
            return null;
        }

        if (preg_match('/([0-9a-f]{32})/i', $reference, $match)) {
            return Artifact::where('uuid', strtolower($match[1]))->where('design_system', true)->first();
        }

        if (preg_match('#^@?([a-z0-9-]+)/([a-z0-9-]+)$#i', $reference, $match)) {
            return Artifact::query()
                ->where('design_system', true)
                ->whereHas('user', fn ($query) => $query->where('username', strtolower($match[1])))
                ->whereHas('project', fn ($query) => $query->where('slug', strtolower($match[2])))
                ->first();
        }

        return null;
    }

    public function forProject(Project $project): ?Artifact
    {
        return $project->artifacts()->where('design_system', true)->first();
    }

    public function ref(Artifact $system): string
    {
        $system->loadMissing('user', 'project');

        return "{$system->user->username}/{$system->project->slug}";
    }

    /**
     * Todos os design systems (de todos os projetos), filtrados por texto.
     *
     * @return Collection<int, Artifact>
     */
    public function search(?string $query = null): Collection
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim((string) $query))) ?: []);

        return Artifact::query()
            ->where('design_system', true)
            ->with(['user', 'project'])
            ->withCount('boards')
            ->orderByDesc('updated_at')
            ->get()
            ->filter(function (Artifact $system) use ($words) {
                if ($words === []) {
                    return true;
                }

                $haystack = mb_strtolower(implode(' ', [
                    $system->title, $system->description, $system->project->name, $system->project->slug, $system->user->username,
                    $system->boards()->pluck('name')->implode(' '), $system->boards()->pluck('tags')->implode(' '),
                ]));

                return collect($words)->every(fn ($word) => str_contains($haystack, $word));
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Artifact $system): array
    {
        $system->loadMissing('user', 'project');

        return [
            'id' => $system->uuid,
            'ref' => $this->ref($system),
            'title' => $system->title,
            'description' => $system->description,
            'project' => $system->project->name,
            'owner' => $system->user->username,
            'components' => $system->boards_count ?? $system->boards()->count(),
            'url' => $system->url(),
            'updated_at' => $system->updated_at->toIso8601String(),
        ];
    }

    public function canEdit(User $user, Artifact $system): bool
    {
        return $user->can('update', $system);
    }
}
