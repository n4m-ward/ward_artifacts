<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Imagem usada num quadro (os "files" do Excalidraw, pelo fileId).
 */
class WhiteboardFile extends Model
{
    protected $fillable = ['artifact_id', 'file_id', 'mime', 'storage_path', 'size_bytes', 'created_by'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }
}
