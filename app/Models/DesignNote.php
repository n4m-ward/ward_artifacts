<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Título (sobre uma fileira de artboards) ou nota adesiva no canvas.
 */
class DesignNote extends Model
{
    public const KINDS = ['title', 'sticky'];

    public const COLORS = ['gray', 'yellow', 'blue', 'green', 'pink', 'purple'];

    protected $fillable = ['artifact_id', 'key', 'kind', 'x', 'y', 'w', 'text', 'color'];

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'w' => 'integer',
        ];
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }
}
