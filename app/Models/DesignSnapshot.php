<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versão congelada de um canvas: um zip com canvas.json + artboards.
 */
class DesignSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['artifact_id', 'number', 'label', 'storage_path', 'size_bytes', 'board_count', 'created_by'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'size_bytes' => 'integer',
            'board_count' => 'integer',
        ];
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }
}
