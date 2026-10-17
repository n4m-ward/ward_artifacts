<?php

namespace App\Http\Controllers;

use App\Actions\Artifacts\RecordArtifactView;
use App\Artifacts\ContentGateway;
use App\Models\Artifact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * /{username}/{projeto}/{titulo}-{uuid}.html: a moldura do artifact. O HTML
 * em si roda num iframe apontando para a origem de conteúdo.
 */
class ArtifactViewerController extends Controller
{
    public function __invoke(
        Request $request,
        string $username,
        string $project,
        string $file,
        ContentGateway $gateway,
        RecordArtifactView $recordView,
    ): Response {
        abort_unless(preg_match('/([0-9a-f]{32})\.html$/', $file, $match), 404);

        $artifact = Artifact::with('user', 'project', 'currentVersion')->where('uuid', $match[1])->first();

        abort_if($artifact?->currentVersion === null, 404);

        if (Gate::denies('view', $artifact)) {
            // Não revela que o artifact existe para quem já está logado.
            return $request->user() ? abort(404) : redirect()->guest(route('login'));
        }

        // Título, projeto ou username mudaram: manda para a URL atual.
        if ('/'.rawurldecode($request->path()) !== $artifact->path()) {
            return redirect($artifact->path(), 301);
        }

        if ($request->user()) {
            $recordView->handle($request->user(), $artifact);
        }

        return response()
            ->view('artifacts.show', [
                'artifact' => $artifact,
                'contentUrl' => $gateway->url($artifact->currentVersion),
                'sandbox' => $gateway->sandbox(),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'same-origin');
    }
}
