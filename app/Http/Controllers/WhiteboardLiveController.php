<?php

namespace App\Http\Controllers;

use App\Models\Artifact;
use App\Whiteboards\WhiteboardPresence;
use App\Whiteboards\WhiteboardState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * /w/{uuid}/live?since=N&p=S: o quadro pergunta o que mudou desde o seq N. 204 se
 * nada mudou (nem os elementos, nem quem está no quadro, nem a IA); só a presença
 * mudou: {unchanged, people, ai}; senão, os elementos novos (ou o quadro inteiro).
 *
 * Quem está logado precisa poder ver o quadro. Sem sessão (link público,
 * pré-visualização), vale a URL assinada que a página entregou.
 */
class WhiteboardLiveController extends Controller
{
    public function __invoke(Request $request, string $artifact, WhiteboardState $state, WhiteboardPresence $presence): Response
    {
        $board = Artifact::where('uuid', $artifact)->first();

        abort_unless($board?->isWhiteboard(), 404);

        $user = $request->user();
        $allowed = $user ? Gate::allows('view', $board) : $request->hasValidSignatureWhileIgnoring(['since', 'p']);

        abort_unless($allowed, 403);

        $headers = ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow'];

        if (! $user) {
            $headers['Access-Control-Allow-Origin'] = '*';
        }

        $presence->touchViewer($board);
        $since = (int) $request->query('since', 0);

        if ($since > 0 && $since === $board->whiteboard_seq) {
            $signature = $presence->signature($board, $user);

            if ($request->query('p') === $signature) {
                return response()->noContent(204, $headers);
            }

            return new JsonResponse([
                'unchanged' => true,
                'seq' => $board->whiteboard_seq,
                'people' => $presence->people($board, $user),
                'ai' => $presence->ai($board),
                'presence' => $signature,
            ], 200, $headers);
        }

        return new JsonResponse($state->live($board, $since, $user), 200, $headers);
    }
}
