<?php

namespace App\Actions\Designs;

use App\Designs\DesignSystems;
use App\Designs\InvalidDesign;
use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Cria o design system de um projeto (um por projeto) ou atualiza título,
 * descrição e documentação. Nasce visível para todos os usuários logados.
 */
class SaveDesignSystem extends DesignMutation
{
    public function __construct(private CreateDesign $create, private DesignSystems $systems) {}

    /**
     * @param  array{title?: ?string, description?: ?string, docs?: ?string}  $data
     */
    public function handle(User $user, string $projectName, array $data): Artifact
    {
        $project = Project::resolveFor($user, $projectName);
        $system = $this->systems->forProject($project);

        if ($system === null) {
            $title = trim((string) ($data['title'] ?? '')) ?: "Design system · {$project->name}";
            $system = $this->create->handle($user, Str::limit($title, 200, ''), $project->name);
            $system->forceFill(['design_system' => true, 'visibility' => Visibility::Users])->save();
        }

        return $this->update($system, $data);
    }

    /**
     * @param  array{title?: ?string, description?: ?string, docs?: ?string}  $data
     */
    public function update(Artifact $system, array $data): Artifact
    {
        if (! $system->isDesignSystem()) {
            throw new InvalidDesign('Este design não é um design system.');
        }

        if (isset($data['docs']) && strlen($data['docs']) > 500_000) {
            throw new InvalidDesign('A documentação passa de 500 KB.');
        }

        $changes = array_filter([
            'title' => isset($data['title']) && trim($data['title']) !== '' ? Str::limit(trim($data['title']), 200, '') : null,
            'description' => array_key_exists('description', $data) ? Str::limit(trim((string) $data['description']), 2000, '') : null,
            'docs' => array_key_exists('docs', $data) ? (string) $data['docs'] : null,
        ], fn ($value) => $value !== null);

        if ($changes !== []) {
            $this->mutate($system, fn () => $system->forceFill($changes)->save());
        }

        return $system->fresh();
    }
}
