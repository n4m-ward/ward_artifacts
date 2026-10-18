<?php

namespace App\Models;

use App\Enums\BoardKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um artboard do canvas: o layout fica aqui, o HTML fica em disco
 * (storage_path aponta para a revisão atual).
 */
class DesignBoard extends Model
{
    protected $fillable = [
        'artifact_id', 'name', 'title', 'kind', 'x', 'y', 'w', 'h',
        'interactive', 'sort', 'revision', 'storage_path', 'size_bytes', 'updated_by', 'page', 'includes', 'description', 'tags',
    ];

    /** <ward-include name="Header"></ward-include>: usa o artboard "Header" como componente. */
    /**
     * <ward-include name="Header"></ward-include> usa o artboard "Header" deste design;
     * <ward-include ds="usuario/projeto" name="Botao"></ward-include> usa o componente de um design system.
     * Grupos: 2 = ds (opcional), 4 = nome.
     */
    public const INCLUDE_PATTERN = '/<ward-include\s+(?:ds=(["\'])([a-z0-9-]+\/[a-z0-9-]+)\1\s+)?name=(["\'])([A-Za-z0-9][A-Za-z0-9 _-]{0,59})\3\s*(?:\/>|>\s*<\/ward-include>)/i';

    /**
     * Referências usadas no HTML: "Header" ou "usuario/projeto:Botao".
     *
     * @return list<string>
     */
    public static function includesIn(string $html): array
    {
        preg_match_all(self::INCLUDE_PATTERN, $html, $matches, PREG_SET_ORDER);

        return array_values(array_unique(array_map(
            fn ($match) => $match[2] !== '' ? strtolower($match[2]).':'.$match[4] : $match[4],
            $matches,
        )));
    }

    protected function casts(): array
    {
        return [
            'kind' => BoardKind::class,
            'x' => 'integer',
            'y' => 'integer',
            'w' => 'integer',
            'h' => 'integer',
            'interactive' => 'boolean',
            'sort' => 'integer',
            'revision' => 'integer',
            'size_bytes' => 'integer',
            'includes' => 'array',
        ];
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }
}
