<?php

namespace App\Http\Controllers;

use App\Artifacts\ContentGateway;
use App\Models\Artifact;
use App\Models\ArtifactPreview;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * /p/{token}: link de pré-visualização de uso único devolvido pelo MCP.
 *
 * - Quem está logado e pode ver o artifact vai para a URL normal (sem gastar o token).
 * - Primeiro acesso sem login: mostra o artifact, queima o token e grava um cookie
 *   naquele navegador, que pode recarregar (F5) por algumas horas.
 * - Qualquer outro acesso (outro navegador, token vencido): 403.
 */
class ArtifactPreviewController extends Controller
{
    private const COOKIE = 'ward_preview';

    public function __invoke(Request $request, string $token, ContentGateway $gateway): Response
    {
        $preview = ArtifactPreview::findByToken($token);
        $artifact = $preview?->artifact()->with('user', 'project', 'currentVersion')->first();

        abort_if($artifact?->currentVersion === null, 404);

        if ($request->user()?->can('view', $artifact)) {
            return redirect($artifact->path());
        }

        $browserKey = $request->cookie(self::COOKIE);
        $sameBrowser = $preview->belongsToBrowser(is_string($browserKey) ? $browserKey : null);

        // HEAD (verificadores de link, curl -I) não gasta o token.
        if ($request->isMethod('HEAD')) {
            abort_unless($preview->isUsable() || $sameBrowser, 403);

            return response('', 200, ['Cache-Control' => 'no-store, private']);
        }

        if (! $sameBrowser) {
            $browserKey = Str::random(40);

            abort_unless(
                $preview->isUsable() && $preview->claim($browserKey),
                403,
                'Este link de pré-visualização já foi usado ou expirou.',
            );
        }

        return $this->render($artifact, $gateway)
            ->withCookie($this->browserCookie($token, $browserKey, $preview));
    }

    private function render(Artifact $artifact, ContentGateway $gateway): Response
    {
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

    /**
     * Cookie só para este link (path /p/{token}), válido até o fim da sessão de
     * pré-visualização.
     */
    private function browserCookie(string $token, string $browserKey, ArtifactPreview $preview): Cookie
    {
        return cookie(
            self::COOKIE,
            $browserKey,
            max(1, (int) ceil(now()->diffInMinutes($preview->sessionExpiresAt()))),
            '/p/'.$token,
            null,
            config('session.secure'),
            true,
            false,
            'lax',
        );
    }
}
