<?php

namespace App\Http\Controllers;

use App\Designs\DesignCanvas;
use App\Enums\ArtifactKind;
use App\Models\Artifact;
use App\Whiteboards\WhiteboardVersions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * Baixa o código da versão atual: o .html, ou um .zip com index.html + assets.
 * Os arquivos baixados abrem direto no navegador, sem o site.
 */
class ArtifactDownloadController extends Controller
{
    public function __invoke(Request $request, Artifact $artifact): Response
    {
        // O MCP devolve um link assinado (15 min) para a IA baixar com curl.
        if (! $request->hasValidSignature()) {
            Gate::authorize('view', $artifact);
        }

        if ($artifact->isWhiteboard()) {
            $document = app(WhiteboardVersions::class)->document($artifact, withFiles: true);

            return response()->streamDownload(
                fn () => print (json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                "{$artifact->slug}.excalidraw",
                ['Content-Type' => 'application/json', 'X-Robots-Tag' => 'noindex, nofollow'],
            );
        }

        if ($artifact->isDesign()) {
            abort_unless($artifact->boards()->exists(), 404);

            $zipPath = tempnam(sys_get_temp_dir(), 'ward-design-');
            app(DesignCanvas::class)->writeZip($artifact, $zipPath);

            return response()
                ->download($zipPath, "{$artifact->slug}.zip", ['X-Robots-Tag' => 'noindex, nofollow'])
                ->deleteFileAfterSend();
        }

        $version = $artifact->currentVersion;
        abort_if($version === null, 404);

        $disk = Storage::disk(config('ward.artifacts.disk'));

        if ($version->kind === ArtifactKind::Html) {
            return $disk->download($version->filePath(), "{$artifact->slug}.html", [
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'ward-download-');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);

        foreach ($disk->allFiles($version->storage_path) as $file) {
            $zip->addFile($disk->path($file), substr($file, strlen($version->storage_path) + 1));
        }

        $zip->close();

        return response()
            ->download($zipPath, "{$artifact->slug}.zip", ['X-Robots-Tag' => 'noindex, nofollow'])
            ->deleteFileAfterSend();
    }
}
