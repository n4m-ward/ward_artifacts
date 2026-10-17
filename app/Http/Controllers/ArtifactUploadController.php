<?php

namespace App\Http\Controllers;

use App\Actions\Artifacts\CreateArtifactPreview;
use App\Actions\Artifacts\PublishArtifact;
use App\Actions\Artifacts\StoreArtifactVersion;
use App\Artifacts\InvalidArtifact;
use App\Models\Artifact;
use App\Models\ArtifactUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;

/**
 * Recebe o arquivo de um link de upload. Aceita o corpo cru
 * (curl --data-binary @index.html) ou multipart (curl -F file=@site.zip).
 */
class ArtifactUploadController extends Controller
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function __invoke(
        Request $request,
        string $token,
        PublishArtifact $publish,
        StoreArtifactVersion $storeVersion,
        CreateArtifactPreview $createPreview,
    ): JsonResponse {
        $upload = ArtifactUpload::findByToken($token);

        if (! $upload?->isUsable()) {
            return $this->error('Este link de upload é inválido, já foi usado ou expirou. Peça um novo ao MCP.', 410);
        }

        $max = (int) config('ward.artifacts.max_bytes');

        if ((int) $request->header('Content-Length') > $max + 1024 * 1024) {
            return $this->error('O envio passa do limite de '.Number::fileSize($max).'.', 413);
        }

        // Reivindica o link antes de processar, para dois envios simultâneos
        // não usarem o mesmo token.
        $claimed = ArtifactUpload::whereKey($upload->id)->whereNull('used_at')->update(['used_at' => now()]);

        if ($claimed === 0) {
            return $this->error('Este link de upload já foi usado.', 410);
        }

        $temporary = null;

        try {
            $sourcePath = $request->hasFile('file')
                ? $request->file('file')->getRealPath()
                : $temporary = $this->bufferBody($request, $max);

            $artifact = $upload->artifact_id
                ? $this->storeNewVersion($upload, $sourcePath, $storeVersion)
                : $publish->handle($upload->user, $upload->title, $upload->project, $sourcePath);
        } catch (InvalidArtifact $e) {
            // O envio foi recusado: o link continua valendo para tentar de novo.
            ArtifactUpload::whereKey($upload->id)->update(['used_at' => null]);

            return $this->error($e->getMessage(), 422);
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }

        $artifact->load('user', 'project', 'currentVersion');

        return response()->json(['artifact' => [
            'id' => $artifact->uuid,
            'title' => $artifact->title,
            'url' => $artifact->url(),
            'version' => $artifact->currentVersion->number,
            'kind' => $artifact->currentVersion->kind->value,
            'size_bytes' => $artifact->size_bytes,
            'visibility' => $artifact->visibility->value,
            // Uso único, sem login: para a IA conferir no navegador dela.
            'preview_url' => $createPreview->handle($upload->user, $artifact),
        ]], $upload->artifact_id ? 200 : 201, [], self::JSON_FLAGS);
    }

    private function storeNewVersion(ArtifactUpload $upload, string $sourcePath, StoreArtifactVersion $storeVersion): Artifact
    {
        $artifact = $upload->artifact;

        Gate::forUser($upload->user)->authorize('update', $artifact);

        $storeVersion->handle($artifact, $upload->user, $sourcePath);

        return $artifact->refresh();
    }

    /**
     * Copia o corpo cru da requisição para um arquivo temporário, parando se
     * passar do limite.
     */
    private function bufferBody(Request $request, int $max): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ward-upload-');
        $target = fopen($path, 'wb');
        $source = $request->getContent(asResource: true);
        $written = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                $written += strlen((string) $chunk);

                if ($written > $max) {
                    throw InvalidArtifact::tooLarge($max);
                }

                fwrite($target, (string) $chunk);
            }
        } catch (InvalidArtifact $e) {
            fclose($target);
            @unlink($path);

            throw $e;
        }

        fclose($target);

        return $path;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status, [], self::JSON_FLAGS);
    }
}
