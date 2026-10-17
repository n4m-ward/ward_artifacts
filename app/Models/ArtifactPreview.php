<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link de pré-visualização de uso único que o MCP devolve para a IA abrir o
 * artifact no navegador dela, sem login. Guarda só o hash do token.
 */
class ArtifactPreview extends Model
{
    use Prunable;

    protected $fillable = ['token_hash', 'artifact_id', 'user_id', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return static::where('token_hash', static::hashToken($token))->first();
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * Marca como usado de forma atômica: só um acesso consegue o "primeiro uso".
     */
    public function claim(): bool
    {
        return static::whereKey($this->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]) === 1;
    }

    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDay());
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
