<?php

namespace App\Actions\Artifacts;

use App\Models\Artifact;

/**
 * Troca o título (e o slug da URL). A URL antiga continua funcionando,
 * porque o artifact é encontrado pelo uuid.
 */
class RenameArtifact
{
    public function handle(Artifact $artifact, string $title): Artifact
    {
        $title = PublishArtifact::cleanTitle($title);

        $artifact->update([
            'title' => $title,
            'slug' => Artifact::slugFor($title),
        ]);

        return $artifact;
    }
}
