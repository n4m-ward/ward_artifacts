<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Imagem enviada para um design. O artboard a usa pelo caminho relativo
 * "assets/{name}" (funciona no canvas, no .zip e fora dele).
 */
class DesignAsset extends Model
{
    protected $fillable = ['artifact_id', 'name', 'original_name', 'mime', 'size_bytes', 'width', 'height', 'created_by'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    public function path(): string
    {
        return "assets/{$this->name}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanvas(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path(),
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
