<?php

namespace App\Actions\Designs;

use App\Actions\Artifacts\PublishArtifact;
use App\Enums\ArtifactType;
use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\Project;
use App\Models\User;

/**
 * Cria um canvas vazio. Como todo artifact, nasce visível só para o dono.
 */
class CreateDesign
{
    public function handle(User $owner, string $title, string $project): Artifact
    {
        $title = PublishArtifact::cleanTitle($title);

        return Artifact::create([
            'uuid' => Artifact::newUuid(),
            'type' => ArtifactType::Design,
            'user_id' => $owner->id,
            'project_id' => Project::resolveFor($owner, $project)->id,
            'title' => $title,
            'slug' => Artifact::slugFor($title),
            'visibility' => Visibility::Owner,
            'design_revision' => 0,
        ])->refresh();
    }
}
