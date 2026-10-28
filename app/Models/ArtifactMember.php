<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pessoa convidada pelo dono para um artifact (hoje: editora de um whiteboard).
 */
class ArtifactMember extends Model
{
    public const EDITOR = 'editor';

    protected $fillable = ['artifact_id', 'user_id', 'role', 'invited_by'];

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
