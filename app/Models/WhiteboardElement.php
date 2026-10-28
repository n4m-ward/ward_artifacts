<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um elemento do Excalidraw num quadro. `data` é o elemento inteiro (texto JSON, sem cast:
 * é gravado em lote com upsert e lido direto como JSON);
 * version/version_nonce decidem quem vence quando duas pessoas gravam o mesmo.
 */
class WhiteboardElement extends Model
{
    protected $fillable = ['artifact_id', 'element_id', 'type', 'version', 'version_nonce', 'is_deleted', 'data', 'seq', 'updated_by'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'version_nonce' => 'integer',
            'is_deleted' => 'boolean',
            'seq' => 'integer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function element(): array
    {
        return json_decode($this->data, true);
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }
}
