<?php

namespace App\Actions\Artifacts;

use App\Models\Artifact;
use App\Models\ArtifactPreview;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Gera o link /p/{token} que a IA abre no navegador dela para ver o artifact
 * sem login. Vale uma vez (e por poucos minutos); depois, só quem estiver
 * logado e puder ver o artifact passa.
 */
class CreateArtifactPreview
{
    public function handle(User $user, Artifact $artifact): string
    {
        $token = Str::random(48);

        ArtifactPreview::create([
            'token_hash' => ArtifactPreview::hashToken($token),
            'artifact_id' => $artifact->id,
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes((int) config('ward.artifacts.preview_ttl_minutes')),
        ]);

        return route('artifacts.preview', $token);
    }
}
