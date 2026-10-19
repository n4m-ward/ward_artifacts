<?php

namespace App\Http\Controllers;

use App\Designs\DesignPresence;
use App\Models\Artifact;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /d/{uuid}/presence (URL assinada, como o ao vivo): o canvas que executou
 * uma ação da IA (clique no Play, apontar um elemento) devolve o que aconteceu.
 */
class DesignPresenceController extends Controller
{
    public function __invoke(Request $request, string $artifact, DesignPresence $presence): Response
    {
        abort_unless($request->hasValidSignatureWhileIgnoring([]), 403);

        $design = Artifact::where('uuid', $artifact)->first();
        abort_unless($design?->isDesign(), 404);

        // Corpo JSON enviado como text/plain: assim o MCP App (outra origem) não precisa de preflight.
        $data = json_decode($request->getContent(), true);
        abort_unless(is_array($data) && is_int($data['seq'] ?? null) && is_array($data['result'] ?? null), 422);

        $presence->report($design, $data['seq'], $data['result']);

        return response()->noContent(204, ['Access-Control-Allow-Origin' => '*']);
    }
}
