<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Agrupador dos artifacts de um usuário: a feature discutida na conversa
 * que gerou o artifact. É o segundo segmento da URL.
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'slug'];

    /**
     * Encontra (ou cria) o projeto do usuário pelo slug do nome informado.
     */
    public static function resolveFor(User $user, string $name): self
    {
        $name = Str::limit(trim($name), 120, '');
        $slug = Str::limit(Str::slug($name), 100, '') ?: 'geral';

        return static::firstOrCreate(
            ['user_id' => $user->id, 'slug' => $slug],
            ['name' => $name !== '' ? $name : 'Geral'],
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }
}
