<?php

namespace App\Actions\Artifacts;

use App\Enums\Visibility;
use App\Models\Artifact;
use InvalidArgumentException;

/**
 * Define quem vê o artifact. Chamado só pela tela do artifact, nunca pelo MCP.
 * O nível base (só eu / usuários logados) e o link público são independentes:
 * o público é sempre temporário e, quando vence, vale de novo o nível base.
 */
class SetArtifactVisibility
{
    public function base(Artifact $artifact, Visibility $visibility): Artifact
    {
        $artifact->update(['visibility' => $visibility]);

        return $artifact;
    }

    public function makePublic(Artifact $artifact, int $hours): Artifact
    {
        if (! in_array($hours, static::publicDurations(), true)) {
            throw new InvalidArgumentException("Duração pública não permitida: {$hours}h.");
        }

        $artifact->update(['public_until' => now()->addHours($hours)]);

        return $artifact;
    }

    public function endPublic(Artifact $artifact): Artifact
    {
        $artifact->update(['public_until' => null]);

        return $artifact;
    }

    /**
     * @return list<int>
     */
    public static function publicDurations(): array
    {
        return array_map('intval', config('ward.artifacts.public_durations'));
    }

    public static function durationLabel(int $hours): string
    {
        return match (true) {
            $hours === 168 => '1 semana',
            $hours >= 48 && $hours % 24 === 0 => ($hours / 24).' dias',
            $hours === 1 => '1 hora',
            default => "{$hours} horas",
        };
    }
}
