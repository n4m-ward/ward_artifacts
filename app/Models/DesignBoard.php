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
        'interactive', 'sort', 'revision', 'storage_path', 'size_bytes', 'updated_by',
    ];

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
        ];
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }
}
