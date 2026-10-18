<?php

namespace App\Designs;

use App\Artifacts\ContentGateway;
use App\Models\Artifact;
use Illuminate\Http\Response;

/**
 * Página do canvas: entrega o estado inicial e a URL do ao vivo para o
 * resources/js/design-canvas.js, que desenha e atualiza o canvas.
 */
class DesignView
{
    public const ALLOW = 'fullscreen; autoplay; clipboard-read; clipboard-write; encrypted-media; gamepad; picture-in-picture';

    public function __construct(private ContentGateway $gateway, private DesignState $state) {}

    public function render(Artifact $design, bool $preview = false): Response
    {
        $ttl = $preview ? (int) config('ward.artifacts.preview_content_ttl_minutes') : null;
        $liveUntil = now()->addMinutes((int) config('ward.artifacts.preview_session_minutes'));

        // Quem chegou pelo link público (sem sessão) só acompanha até o link vencer.
        if (! $preview && auth()->guest() && $design->public_until !== null) {
            $liveUntil = $liveUntil->min($design->public_until);
        }

        $state = $this->state->toArray($design, $ttl, comments: ! $preview && auth()->check());
        $editable = ! $preview && auth()->user()?->can('update', $design);
        $collab = ! $preview && auth()->check();

        return response()->view('artifacts.design', [
            'artifact' => $design,
            'preview' => $preview,
            'boards' => $state['boards'],
            'config' => [
                'design' => $design->uuid,
                'state' => $state,
                'liveUrl' => $this->state->liveUrl($design, $liveUntil),
                'contentOrigin' => $this->gateway->origin(),
                'sandbox' => $this->gateway->sandbox(),
                'allow' => self::ALLOW,
                // Edição à mão (resources/js/design-editor.js): só dono ou admin.
                'edit' => $editable ? [
                    'url' => route('designs.edit', $design->uuid),
                    'sourceUrl' => route('designs.board-source', [$design->uuid, 0]),
                    'assetsUrl' => route('designs.assets.index', $design->uuid),
                    'csrf' => csrf_token(),
                ] : null,
                // Comentários e o relato do que a pessoa vê (qualquer pessoa logada que vê o design).
                'collab' => $collab ? [
                    'user' => ['id' => auth()->id(), 'name' => auth()->user()->name],
                    'commentsUrl' => route('designs.comments.store', $design->uuid),
                    'viewUrl' => route('designs.view', $design->uuid),
                    'printUrl' => route('designs.print', $design->uuid),
                    'csrf' => csrf_token(),
                ] : null,
            ],
            'editable' => $collab,
        ]);
    }
}
