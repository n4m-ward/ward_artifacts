<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Página de um design: um grupo de artboards e notas (abas no canvas).
 * Artboard sem página fica na primeira.
 */
class DesignPage extends Model
{
    protected $fillable = ['artifact_id', 'key', 'name', 'sort'];

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }
}
