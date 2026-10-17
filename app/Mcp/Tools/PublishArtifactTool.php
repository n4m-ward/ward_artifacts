<?php

namespace App\Mcp\Tools;

use App\Actions\Artifacts\CreateArtifactUpload;
use App\Actions\Artifacts\PublishArtifact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('publish_artifact')]
#[Description('Publica um artifact NOVO. Sempre nasce privado (só o dono vê); só o usuário pode compartilhar, na tela do artifact. Envie o HTML completo no campo "html" (até 2 MB), ou omita "html" para receber um link de upload (use para arquivos grandes ou bundles .zip com index.html + assets). Para alterar um artifact existente use update_artifact, não publique outro. A resposta traz preview_url: link de uso único, sem login, para você conferir o resultado no seu navegador (não é o link para o usuário).')]
class PublishArtifactTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(200)->description('Título curto e descritivo do artifact.')->required(),
            'project' => $schema->string()->max(120)->description('Nome da feature/assunto da conversa atual (não o nome do repositório). Artifacts da mesma feature devem usar o mesmo nome. Veja list_projects para reaproveitar.')->required(),
            'html' => $schema->string()->description('HTML completo e autocontido (CSS/JS/imagens inline). Omita para receber um link de upload.'),
        ];
    }

    public function handle(Request $request, PublishArtifact $publish, CreateArtifactUpload $uploads): Response|ResponseFactory
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'project' => ['required', 'string', 'max:120'],
            'html' => ['nullable', 'string'],
        ]);

        $user = $this->user($request);

        if (blank($validated['html'] ?? null)) {
            $pending = $uploads->forNew($user, $validated['title'], $validated['project']);

            return Response::make(Response::text($this->uploadInstructions($pending->url, $pending->expiresAt->toIso8601String())))
                ->withStructuredContent(['upload_url' => $pending->url, 'expires_at' => $pending->expiresAt->toIso8601String()]);
        }

        return $this->withInlineHtml($validated['html'], function (string $path) use ($publish, $user, $validated) {
            $artifact = $publish->handle($user, $validated['title'], $validated['project'], $path);
            $preview = $this->previewUrl($user, $artifact);

            return Response::make(Response::text("Artifact publicado (privado): {$artifact->url()}\n\n".$this->previewHint($preview)))
                ->withStructuredContent($this->artifactData($artifact) + ['preview_url' => $preview]);
        });
    }
}
