<?php

namespace App\Models;

use App\Actions\Artifacts\DeleteArtifact;
use App\Actions\Mcp\RevokeMcpConnection;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'created_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * Convidado que ainda não definiu a senha.
     */
    public function hasPendingInvite(): bool
    {
        return $this->email_verified_at === null;
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    /**
     * Artifacts de outros usuários que este usuário já abriu.
     */
    public function viewedArtifacts(): BelongsToMany
    {
        return $this->belongsToMany(Artifact::class, 'artifact_views')
            ->withPivot('first_viewed_at', 'last_viewed_at');
    }

    protected static function booted(): void
    {
        // As linhas somem por cascade no banco; os arquivos precisam sair à mão.
        static::deleting(function (User $user) {
            $user->artifacts()->each(fn (Artifact $artifact) => app(DeleteArtifact::class)->handle($artifact));

            // Corta as conexões MCP (a tabela de tokens do Passport não tem FK).
            app(RevokeMcpConnection::class)->allFor($user);
        });
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
