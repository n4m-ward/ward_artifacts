<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Artifact sem arquivos. Para ter conteúdo no disco, use PublishArtifact.
 *
 * @extends Factory<Artifact>
 */
class ArtifactFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'uuid' => Artifact::newUuid(),
            'project_id' => Project::factory(),
            'user_id' => fn (array $attributes) => Project::find($attributes['project_id'])->user_id,
            'title' => $title,
            'slug' => Artifact::slugFor($title),
            'visibility' => Visibility::Owner,
        ];
    }

    public function visibleToUsers(): static
    {
        return $this->state(['visibility' => Visibility::Users]);
    }

    public function publicFor(int $hours): static
    {
        return $this->state(['public_until' => now()->addHours($hours)]);
    }

    public function publicExpired(): static
    {
        return $this->state(['public_until' => now()->subMinute()]);
    }
}
