<?php

namespace App\Actions\Artifacts;

use App\Models\Artifact;
use App\Models\ArtifactUpload;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Gera o link de upload de uso único que o MCP devolve para a IA enviar o
 * arquivo (curl --data-binary @arquivo "<url>"), já que um MCP HTTP não lê o
 * disco de quem o chama.
 */
class CreateArtifactUpload
{
    /**
     * Upload que cria um artifact novo.
     */
    public function forNew(User $user, string $title, string $project): PendingUpload
    {
        return $this->create($user, [
            'title' => PublishArtifact::cleanTitle($title),
            'project' => $project,
        ]);
    }

    /**
     * Upload que vira uma nova versão de um artifact existente.
     */
    public function forVersion(User $user, Artifact $artifact): PendingUpload
    {
        return $this->create($user, ['artifact_id' => $artifact->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(User $user, array $attributes): PendingUpload
    {
        $token = Str::random(48);
        $expiresAt = now()->addMinutes((int) config('ward.artifacts.upload_ttl_minutes'));

        $upload = ArtifactUpload::create([
            ...$attributes,
            'token_hash' => ArtifactUpload::hashToken($token),
            'user_id' => $user->id,
            'expires_at' => $expiresAt,
        ]);

        return new PendingUpload($upload, route('artifact-uploads.store', $token), $expiresAt);
    }
}
