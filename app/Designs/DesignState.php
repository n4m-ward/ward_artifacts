<?php

namespace App\Designs;

use App\Artifacts\ContentGateway;
use App\Models\Artifact;
use App\Models\DesignBoard;
use App\Models\DesignNote;
use DateTimeInterface;
use Illuminate\Support\Facades\URL;

/**
 * Estado do canvas que o navegador desenha: artboards (com URL assinada do
 * conteúdo), notas e a revisão. É o mesmo JSON na carga da página e no ao vivo.
 */
class DesignState
{
    public function __construct(private ContentGateway $gateway) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(Artifact $design, ?int $contentTtlMinutes = null): array
    {
        return [
            'revision' => $design->design_revision,
            'boards' => $design->orderedBoards()->get()->map(fn (DesignBoard $board) => [
                'id' => $board->id,
                'name' => $board->name,
                'title' => $board->title,
                'kind' => $board->kind->value,
                'x' => $board->x,
                'y' => $board->y,
                'w' => $board->w,
                'h' => $board->h,
                'interactive' => $board->interactive,
                'revision' => $board->revision,
                'url' => $this->gateway->boardUrl($board, $contentTtlMinutes),
            ])->values()->all(),
            'notes' => $design->notes()->orderBy('id')->get()->map(fn (DesignNote $note) => [
                'key' => $note->key,
                'kind' => $note->kind,
                'x' => $note->x,
                'y' => $note->y,
                'w' => $note->w,
                'text' => $note->text,
                'color' => $note->color,
            ])->values()->all(),
        ];
    }

    /**
     * URL do ao vivo para esta página. Assinada, para funcionar também em quem
     * abriu por link público ou pré-visualização (sem sessão).
     */
    public function liveUrl(Artifact $design, DateTimeInterface $expires): string
    {
        return URL::temporarySignedRoute('designs.live', $expires, ['artifact' => $design->uuid]);
    }
}
