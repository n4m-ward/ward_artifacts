<?php

namespace App\Designs;

use App\Artifacts\ContentGateway;
use App\Models\Artifact;
use App\Models\DesignBoard;
use App\Models\DesignNote;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
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
            'notes' => $design->notes()->orderBy('id')->get()->map(fn (DesignNote $note) => $note->toCanvas())->values()->all(),
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

    /**
     * Tudo que o MCP App precisa para desenhar e acompanhar o canvas.
     *
     * @return array<string, mixed>
     */
    public function appConfig(Artifact $design): array
    {
        return [
            'design' => $design->uuid,
            'title' => $design->title,
            'url' => $design->url(),
            'state' => $this->toArray($design),
            'liveUrl' => $this->liveUrl($design, now()->addMinutes((int) config('ward.artifacts.preview_session_minutes'))),
            'contentOrigin' => $this->gateway->origin(),
            'sandbox' => $this->gateway->sandbox(),
            'allow' => DesignView::ALLOW,
            // O app busca de novo se o estado for velho (chat reaberto, tokens vencidos).
            'issuedAt' => now()->getTimestampMs(),
        ];
    }

    /**
     * O MCP App avisa qual artboard a pessoa selecionou; get_design repassa à IA.
     */
    public function reportViewing(Artifact $design, User $user, ?string $board): void
    {
        $key = $this->viewingKey($design, $user);

        if ($board === null) {
            Cache::forget($key);

            return;
        }

        Cache::put($key, ['board' => $board, 'at' => now()->toIso8601String()], now()->addMinutes((int) config('ward.designs.viewing_ttl_minutes')));
    }

    /**
     * @return array{board: string, at: string}|null
     */
    public function viewing(Artifact $design, User $user): ?array
    {
        $viewing = Cache::get($this->viewingKey($design, $user));

        // O artboard pode ter sido apagado ou renomeado depois.
        return $viewing && $design->boards()->where('name', $viewing['board'])->exists() ? $viewing : null;
    }

    private function viewingKey(Artifact $design, User $user): string
    {
        return "ward:design-viewing:{$design->id}:{$user->id}";
    }
}
