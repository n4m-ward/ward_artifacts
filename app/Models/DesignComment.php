<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Comentário no canvas de um design. Um tópico tem número (#1, #2...) e pode
 * ter respostas (parent_id). A âncora é um elemento de um artboard, o artboard
 * inteiro ou um ponto livre do canvas.
 */
class DesignComment extends Model
{
    protected $fillable = [
        'artifact_id', 'parent_id', 'number', 'user_id', 'board_id', 'board_name', 'anchor', 'x', 'y',
        'body', 'source', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'anchor' => 'array',
            'x' => 'integer',
            'y' => 'integer',
            'number' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(DesignBoard::class, 'board_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Como o tópico aparece no canvas e para a IA.
     *
     * @return array<string, mixed>
     */
    public function toThread(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'board' => $this->board_id,
            'board_name' => $this->board?->name ?? $this->board_name,
            'anchor' => $this->anchor,
            'x' => $this->x,
            'y' => $this->y,
            'resolved' => $this->resolved_at !== null,
            'resolved_by' => $this->resolver?->name,
            'messages' => collect([$this])->concat($this->replies)->map(fn (self $message) => [
                'id' => $message->id,
                'author' => $message->user?->name ?? 'Usuário removido',
                'user_id' => $message->user_id,
                'body' => $message->body,
                'source' => $message->source,
                'at' => $message->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
