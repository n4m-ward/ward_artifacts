<?php

namespace App\Mcp\Tools;

use App\Actions\Artifacts\CreateArtifactUpload;
use App\Actions\Artifacts\StoreArtifactVersion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_artifact')]
#[Description('Envia uma NOVA VERSÃO de um artifact existente, mantendo a mesma URL e a visibilidade. Mande o HTML completo no campo "html" (até 2 MB), ou omita para receber um link de upload (arquivos grandes ou bundle .zip). Só o dono ou um admin pode atualizar. A resposta traz preview_url (uso único, sem login) para conferir a nova versão no seu navegador.')]
class UpdateArtifactTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'artifact' => $schema->string()->description('Id (32 caracteres) ou URL do artifact.')->required(),
            'html' => $schema->string()->description('HTML completo da nova versão. Omita para receber um link de upload.'),
        ];
    }

    public function handle(Request $request, StoreArtifactVersion $storeVersion, CreateArtifactUpload $uploads): Response|ResponseFactory
    {
        $request->validate(['artifact' => ['required', 'string'], 'html' => ['nullable', 'string']]);

        $user = $this->user($request);
        $artifact = $this->findArtifact($request->get('artifact'));

        if (! $artifact || $user->cannot('view', $artifact)) {
            return Response::error('Artifact não encontrado.');
        }

        if ($user->cannot('update', $artifact)) {
            return Response::error('Só o dono do artifact ou um admin pode enviar novas versões.');
        }

        if (blank($request->get('html'))) {
            $pending = $uploads->forVersion($user, $artifact);

            return Response::make(Response::text($this->uploadInstructions($pending->url, $pending->expiresAt->toIso8601String())))
                ->withStructuredContent(['upload_url' => $pending->url, 'expires_at' => $pending->expiresAt->toIso8601String()]);
        }

        return $this->withInlineHtml($request->get('html'), function (string $path) use ($storeVersion, $artifact, $user) {
            $version = $storeVersion->handle($artifact, $user, $path);
            $artifact->refresh();
            $preview = $this->previewUrl($user, $artifact);

            return Response::make(Response::text("Versão {$version->number} publicada: {$artifact->url()}\n\n".$this->previewHint($preview)))
                ->withStructuredContent($this->artifactData($artifact) + ['preview_url' => $preview]);
        });
    }
}
