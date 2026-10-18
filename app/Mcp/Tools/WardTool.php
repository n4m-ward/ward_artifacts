<?php

namespace App\Mcp\Tools;

use App\Actions\Artifacts\CreateArtifactPreview;
use App\Artifacts\InvalidArtifact;
use App\Designs\DesignSystems;
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
     * Aceita o id (32 caracteres hex), a URL completa do artifact ou, para design
     * systems, "usuario/projeto".
     */
    protected function findArtifact(?string $reference): ?Artifact
    {
        // Design system também pela referência curta "usuario/projeto".
        if ($reference && preg_match('#^@?[a-z0-9-]+/[a-z0-9-]+$#i', trim($reference))) {
            return app(DesignSystems::class)->find($reference)?->loadMissing('user', 'project', 'currentVersion');
        }

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
            'type' => $artifact->type->value,
            'version' => $artifact->currentVersion?->number,
            'kind' => $artifact->currentVersion?->kind->value,
            'size' => Number::fileSize($artifact->size_bytes, precision: 1),
            'visibility' => $artifact->isPublic() ? 'public' : $artifact->visibility->value,
            'public_until' => $artifact->public_until?->isFuture() ? $artifact->public_until->toIso8601String() : null,
            'updated_at' => $artifact->updated_at->toIso8601String(),
        ];
    }

    /**
     * Um design que o usuário pode ver, ou um erro pronto para devolver.
     */
    protected function findDesign(Request $request, ?string $reference): Artifact|Response
    {
        $design = $this->findArtifact($reference);

        if (! $design || $this->user($request)->cannot('view', $design)) {
            return Response::error('Design não encontrado.');
        }

        if (! $design->isDesign()) {
            return Response::error('Este artifact é uma página, não um design. Use get_artifact_source/update_artifact.');
        }

        return $design;
    }

    protected function cannotEdit(Request $request, Artifact $design): ?Response
    {
        return $this->user($request)->cannot('update', $design)
            ? Response::error('Só o dono do design ou um admin pode alterá-lo.')
            : null;
    }

    /**
     * Link /p/{token} de uso único para a IA abrir o artifact no navegador
     * dela sem login (ver ArtifactPreviewController).
     */
    protected function previewUrl(User $user, Artifact $artifact): string
    {
        return app(CreateArtifactPreview::class)->handle($user, $artifact);
    }

    protected function previewHint(string $previewUrl): string
    {
        return "Pré-visualização para você conferir no seu navegador (sem login, vale uma única vez): {$previewUrl}\n"
            .'Para o usuário, entregue a URL normal do artifact, não este link.';
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

        A resposta é um JSON com a URL do artifact (artifact.url, para o usuário) e
        um artifact.preview_url de uso único para você conferir no seu navegador.
        Se vier um erro 422, corrija o arquivo e envie de novo para o mesmo link.
        TXT;
    }
}
