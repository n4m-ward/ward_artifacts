<?php

namespace App\Whiteboards;

use App\Models\Artifact;
use Illuminate\Http\Response;

/**
 * Página do quadro: entrega a configuração para resources/js/whiteboard/main.jsx,
 * que monta o Excalidraw e cuida do ao vivo.
 */
class WhiteboardView
{
    public function __construct(private WhiteboardState $state) {}

    public function render(Artifact $board, bool $preview = false): Response
    {
        return response()->view('artifacts.whiteboard', [
            'artifact' => $board,
            'preview' => $preview,
            'elementCount' => $board->whiteboardElements()->where('is_deleted', false)->count(),
            'config' => $this->state->config($board, auth()->user(), $preview, csrf_token()),
        ]);
    }
}
