<?php

namespace App\Actions\Artifacts;

use App\Models\Artifact;
use App\Models\ArtifactView;
use App\Models\User;

/**
 * Marca que o usuário abriu o artifact de outra pessoa: é o que faz o
 * artifact aparecer na aba "Acessados" do painel dele.
 */
class RecordArtifactView
{
    public function handle(User $user, Artifact $artifact): void
    {
        if ($user->id === $artifact->user_id) {
            return;
        }

        $now = now();

        ArtifactView::upsert(
            [[
                'user_id' => $user->id,
                'artifact_id' => $artifact->id,
                'first_viewed_at' => $now,
                'last_viewed_at' => $now,
            ]],
            ['user_id', 'artifact_id'],
            ['last_viewed_at'],
        );
    }
}
