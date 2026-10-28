<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Onde uma pessoa está no quadro agora (cursor, seleção, câmera). Uma linha por pessoa.
 */
class WhiteboardPresence extends Model
{
    public $timestamps = false;

    protected $fillable = ['artifact_id', 'user_id', 'data', 'seen_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
