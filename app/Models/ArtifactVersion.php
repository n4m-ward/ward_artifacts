<?php

namespace App\Models;

use App\Enums\ArtifactKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtifactVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'artifact_id', 'number', 'kind', 'storage_path', 'entry_path',
        'size_bytes', 'file_count', 'sha256', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ArtifactKind::class,
            'number' => 'integer',
            'size_bytes' => 'integer',
            'file_count' => 'integer',
        ];
    }

    /**
     * Caminho no disco de artifacts de um arquivo desta versão.
     */
    public function filePath(?string $relative = null): string
    {
        return $this->storage_path.'/'.ltrim($relative ?? $this->entry_path, '/');
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
