<?php

namespace App\Actions\Artifacts;

use App\Enums\Visibility;
use App\Models\Artifact;
use InvalidArgumentException;

/**
 * Define quem vê o artifact. Chamado só pela tela do artifact, nunca pelo MCP.
 * O público é sempre temporário: escolhido entre as durações permitidas.
 */
class SetArtifactVisibility
{
    public function handle(Artifact $artifact, Visibility $visibility, ?int $publicHours = null): Artifact
    {
        if ($publicHours !== null && ! in_array($publicHours, static::publicDurations(), true)) {
            throw new InvalidArgumentException("Duração pública não permitida: {$publicHours}h.");
        }

        $artifact->update([
            'visibility' => $visibility,
            'public_until' => $publicHours !== null ? now()->addHours($publicHours) : null,
        ]);

        return $artifact;
    }

    /**
     * @return list<int>
     */
    public static function publicDurations(): array
    {
        return array_map('intval', config('ward.artifacts.public_durations'));
    }
}
