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

    public const COLORS = ['gray', 'yellow', 'orange', 'red', 'green', 'teal', 'blue', 'purple', 'pink'];

    public const SIZES = ['s', 'm', 'l', 'xl'];

    protected $fillable = ['artifact_id', 'key', 'kind', 'page', 'x', 'y', 'w', 'text', 'color', 'size', 'bold'];

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'w' => 'integer',
            'bold' => 'boolean',
        ];
    }

    /**
     * Como a nota aparece no estado do canvas, no canvas.json e para a IA.
     *
     * @return array<string, mixed>
     */
    public function toCanvas(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind,
            'page' => $this->page,
            'x' => $this->x,
            'y' => $this->y,
            'w' => $this->w,
            'text' => $this->text,
            'color' => $this->color,
            'size' => $this->size,
            'bold' => $this->bold,
        ];
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }
}
