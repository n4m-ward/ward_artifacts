<?php

namespace App\Models;

use App\Enums\ArtifactType;
use App\Enums\Visibility;
use Database\Factories\ArtifactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Artifact extends Model
{
    /** @use HasFactory<ArtifactFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid', 'user_id', 'project_id', 'title', 'slug',
        'visibility', 'public_until', 'current_version_id', 'size_bytes',
        'type', 'design_revision', 'design_system', 'description', 'docs', 'uses_design_system_id',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
            'type' => ArtifactType::class,
            'design_revision' => 'integer',
            'design_system' => 'boolean',
            'public_until' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public static function newUuid(): string
    {
        return str_replace('-', '', (string) Str::uuid());
    }

    public static function slugFor(string $title): string
    {
        return Str::limit(Str::slug($title), 80, '') ?: 'artifact';
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isDesign(): bool
    {
        return $this->type === ArtifactType::Design;
    }

    /**
     * Tem conteúdo para mostrar: uma versão (página) ou ao menos um artboard (design).
     */
    public function hasContent(): bool
    {
        return $this->isDesign()
            ? $this->boards()->exists()
            : $this->current_version_id !== null;
    }

    public function isPublic(): bool
    {
        return $this->public_until !== null && $this->public_until->isFuture();
    }

    /**
     * Caminho público do artifact: /{username}/{projeto}/{titulo}-{uuid}.html
     */
    public function path(): string
    {
        return "/{$this->user->username}/{$this->project->slug}/{$this->slug}-{$this->uuid}.html";
    }

    public function url(): string
    {
        return url($this->path());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('public_until', '>', now());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ArtifactVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ArtifactVersion::class, 'current_version_id');
    }

    // Relações sem ordenação: agregados (max, sum, count) sobre elas precisam
    // funcionar no MySQL com ONLY_FULL_GROUP_BY. Para listar, use as ordered*.
    public function boards(): HasMany
    {
        return $this->hasMany(DesignBoard::class);
    }

    public function orderedBoards(): HasMany
    {
        return $this->boards()->orderBy('sort')->orderBy('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(DesignNote::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DesignComment::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(DesignAsset::class);
    }

    public function usesDesignSystem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'uses_design_system_id');
    }

    public function isDesignSystem(): bool
    {
        return $this->design_system && $this->isDesign();
    }

    public function pages(): HasMany
    {
        return $this->hasMany(DesignPage::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(DesignSnapshot::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(ArtifactView::class);
    }

    public function viewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'artifact_views')
            ->withPivot('first_viewed_at', 'last_viewed_at');
    }
}
