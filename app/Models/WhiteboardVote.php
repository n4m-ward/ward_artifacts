<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Votos de uma pessoa num elemento durante a votação do quadro.
 */
class WhiteboardVote extends Model
{
    protected $fillable = ['artifact_id', 'element_id', 'user_id', 'count'];

    protected function casts(): array
    {
        return ['count' => 'integer'];
    }
}
