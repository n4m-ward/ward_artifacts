<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Link de pré-visualização de uso único que o MCP devolve para a IA abrir o
 * artifact no navegador dela, sem login. Guarda só o hash do token.
 */
class ArtifactPreview extends Model
{
    use Prunable;

    protected $fillable = ['token_hash', 'artifact_id', 'user_id', 'expires_at', 'used_at', 'browser_key_hash'];

    protected $hidden = ['token_hash', 'browser_key_hash'];

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
     * Marca como usado de forma atômica, amarrando o link ao navegador que o
     * abriu: só um acesso consegue o "primeiro uso".
     */
    public function claim(string $browserKey): bool
    {
        $claimed = static::whereKey($this->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now(), 'browser_key_hash' => static::hashToken($browserKey)]) === 1;

        if ($claimed) {
            $this->refresh();
        }

        return $claimed;
    }

    /**
     * O navegador que usou o link pode recarregar enquanto a sessão dele valer.
     */
    public function belongsToBrowser(?string $browserKey): bool
    {
        return $browserKey !== null
            && $this->used_at !== null
            && $this->browser_key_hash !== null
            && hash_equals($this->browser_key_hash, static::hashToken($browserKey))
            && $this->sessionExpiresAt()->isFuture();
    }

    public function sessionExpiresAt(): Carbon
    {
        return ($this->used_at ?? now())->copy()->addMinutes((int) config('ward.artifacts.preview_session_minutes'));
    }

    public function prunable(): Builder
    {
        // Vencido há mais de um dia e sem sessão de navegador ainda valendo.
        return static::where('expires_at', '<', now()->subDay())
            ->where(fn ($query) => $query->whereNull('used_at')
                ->orWhere('used_at', '<', now()->subMinutes((int) config('ward.artifacts.preview_session_minutes'))->subDay()));
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
