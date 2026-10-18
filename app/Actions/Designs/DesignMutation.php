<?php

namespace App\Actions\Designs;

use App\Designs\InvalidDesign;
use App\Models\Artifact;
use Illuminate\Support\Facades\DB;

/**
 * Base das ações que mudam um canvas: tudo numa transação e, no fim,
 * o relógio do canvas (design_revision) sobe e o tamanho é recalculado.
 */
abstract class DesignMutation
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function mutate(Artifact $design, callable $callback): mixed
    {
        if (! $design->isDesign()) {
            throw new InvalidDesign('Este artifact não é um design.');
        }

        return DB::transaction(function () use ($design, $callback) {
            $result = $callback();

            $design->forceFill([
                'design_revision' => $design->design_revision + 1,
                'size_bytes' => (int) $design->boards()->sum('size_bytes') + (int) $design->assets()->sum('size_bytes'),
            ])->save();

            return $result;
        });
    }

    /**
     * Chave de página válida neste design (null ou "" = primeira página).
     */
    public static function pageKey(Artifact $design, mixed $page): ?string
    {
        if ($page === null || $page === '') {
            return null;
        }

        if (! $design->pages()->where('key', (string) $page)->exists()) {
            throw new InvalidDesign("Página não encontrada: \"{$page}\". Crie com update_layout (pages).");
        }

        return (string) $page;
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]{0,59}$/', $name);
    }
}
