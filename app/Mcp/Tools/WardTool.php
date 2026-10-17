<?php

namespace App\Mcp\Tools;

use App\Artifacts\InvalidArtifact;
use App\Models\Artifact;
use App\Models\User;
use Closure;
use Illuminate\Support\Number;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Base das ferramentas do Ward: resolve artifacts por id ou URL e padroniza
 * o que volta para a IA.
 */
abstract class WardTool extends Tool
{
    protected function user(Request $request): User
    {
        return $request->user();
    }

    /**
     * Aceita o id (32 caracteres hex) ou a URL completa do artifact.
     */
    protected function findArtifact(?string $reference): ?Artifact
    {
        if (! $reference || ! preg_match('/([0-9a-f]{32})/i', $reference, $match)) {
            return null;
        }

        return Artifact::with('user', 'project', 'currentVersion')->where('uuid', strtolower($match[1]))->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function artifactData(Artifact $artifact): array
    {
        $artifact->loadMissing('user', 'project', 'currentVersion');

        return [
            'id' => $artifact->uuid,
            'title' => $artifact->title,
            'url' => $artifact->url(),
            'owner' => $artifact->user->username,
            'project' => $artifact->project->name,
            'project_slug' => $artifact->project->slug,
            'version' => $artifact->currentVersion?->number,
            'kind' => $artifact->currentVersion?->kind->value,
            'size' => Number::fileSize($artifact->size_bytes, precision: 1),
            'visibility' => $artifact->isPublic() ? 'public' : $artifact->visibility->value,
            'public_until' => $artifact->public_until?->isFuture() ? $artifact->public_until->toIso8601String() : null,
            'updated_at' => $artifact->updated_at->toIso8601String(),
        ];
    }

    /**
     * Grava o HTML recebido inline num arquivo temporário e roda $callback.
     */
    protected function withInlineHtml(string $html, Closure $callback): Response|ResponseFactory
    {
        $max = (int) config('ward.artifacts.mcp_inline_max_bytes');

        if (strlen($html) > $max) {
            return Response::error('O HTML passa de '.Number::fileSize($max).' para envio inline. Chame de novo sem o campo "html" para receber um link de upload.');
        }

        $path = tempnam(sys_get_temp_dir(), 'ward-mcp-');
        file_put_contents($path, $html);

        try {
            return $callback($path);
        } catch (InvalidArtifact $e) {
            return Response::error($e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    protected function uploadInstructions(string $url, string $expiresAt): string
    {
        return <<<TXT
        Envie o arquivo para este link (uso único, vale até {$expiresAt}):

        {$url}

        HTML único:
          curl -sS -X PUT --data-binary @caminho/index.html "{$url}"

        Bundle (.zip com index.html + assets):
          curl -sS -F file=@caminho/site.zip "{$url}"

        A resposta é um JSON com a URL do artifact. Se vier um erro 422, corrija o
        arquivo e envie de novo para o mesmo link.
        TXT;
    }
}
