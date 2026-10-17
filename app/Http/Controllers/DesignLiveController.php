<?php

namespace App\Http\Controllers;

use App\Designs\DesignState;
use App\Models\Artifact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * /d/{uuid}/live?rev=N: o canvas pergunta se algo mudou. 204 se a revisão é a
 * mesma; senão, o estado completo (artboards com URLs novas, notas).
 *
 * Quem está logado precisa poder ver o design. Sem sessão (link público,
 * pré-visualização ou o MCP App no Claude), vale a URL assinada que a página
 * ou a ferramenta entregou.
 */
class DesignLiveController extends Controller
{
    public function __invoke(Request $request, string $artifact, DesignState $state): Response
    {
        $design = Artifact::where('uuid', $artifact)->first();

        abort_unless($design?->isDesign(), 404);

        // A assinatura só é emitida para quem já abriu a página com permissão
        // (inclusive por pré-visualização), e vence com a sessão dela.
        $allowed = $request->user() ? Gate::allows('view', $design) : $request->hasValidSignatureWhileIgnoring(['rev']);

        abort_unless($allowed, 403);

        $headers = ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow'];

        // Sem sessão a credencial é a assinatura: o MCP App (outra origem, sem
        // cookies) pode ler a resposta.
        if (! $request->user()) {
            $headers['Access-Control-Allow-Origin'] = '*';
        }

        if ((int) $request->query('rev', -1) === $design->design_revision) {
            return response()->noContent(204, $headers);
        }

        $ttl = $request->user() ? null : (int) config('ward.artifacts.preview_content_ttl_minutes');

        return new JsonResponse($state->toArray($design, $ttl), 200, $headers);
    }
}
