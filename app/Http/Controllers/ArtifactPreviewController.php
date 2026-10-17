<?php

namespace App\Http\Controllers;

use App\Artifacts\ContentGateway;
use App\Models\ArtifactPreview;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /p/{token}: link de pré-visualização de uso único devolvido pelo MCP.
 *
 * - Quem está logado e pode ver o artifact vai para a URL normal (sem gastar o token).
 * - Primeiro acesso sem login: mostra o artifact e queima o token.
 * - Depois disso (ou vencido): 403 para quem não estiver logado com permissão.
 */
class ArtifactPreviewController extends Controller
{
    public function __invoke(Request $request, string $token, ContentGateway $gateway): Response
    {
        $preview = ArtifactPreview::findByToken($token);
        $artifact = $preview?->artifact()->with('user', 'project', 'currentVersion')->first();

        abort_if($artifact?->currentVersion === null, 404);

        if ($request->user()?->can('view', $artifact)) {
            return redirect($artifact->path());
        }

        // HEAD (verificadores de link, curl -I) não gasta o token.
        if ($request->isMethod('HEAD')) {
            abort_unless($preview->isUsable(), 403);

            return response('', 200, ['Cache-Control' => 'no-store, private']);
        }

        abort_unless($preview->isUsable() && $preview->claim(), 403, 'Este link de pré-visualização já foi usado ou expirou.');

        return response()
            ->view('artifacts.show', [
                'artifact' => $artifact,
                'contentUrl' => $gateway->url(
                    $artifact->currentVersion,
                    ttlMinutes: (int) config('ward.artifacts.preview_content_ttl_minutes'),
                ),
                'sandbox' => $gateway->sandbox(),
                'preview' => true,
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
