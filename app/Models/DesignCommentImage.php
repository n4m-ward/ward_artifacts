<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * Imagem anexada a um comentário do canvas. Sem comment_id: enviada, ainda não publicada.
 */
class DesignCommentImage extends Model
{
    protected $fillable = ['artifact_id', 'comment_id', 'user_id', 'name', 'mime', 'width', 'height', 'size_bytes', 'sort'];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'sort' => 'integer',
        ];
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(DesignComment::class, 'comment_id');
    }

    public function storagePath(?Artifact $design = null): string
    {
        return ($design ?? $this->artifact)->uuid.'/comments/'.$this->name;
    }

    /**
     * Link assinado (funciona sem sessão, como no MCP App). Vence na hora cheia, para o
     * mesmo link servir a várias leituras seguidas do estado.
     *
     * @return array{id: int, url: string, width: ?int, height: ?int}
     */
    public function toCanvas(?Artifact $design = null): array
    {
        $design ??= $this->artifact;

        return [
            'id' => $this->id,
            'url' => URL::temporarySignedRoute('designs.comment-images.show', now()->addHours(12)->startOfHour(), [
                'artifact' => $design->uuid,
                'name' => $this->name,
            ]),
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
