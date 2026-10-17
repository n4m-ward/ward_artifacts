<?php

namespace App\Mcp\Tools;

use App\Artifacts\ContentGateway;
use App\Enums\ArtifactKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_artifact_source')]
#[Description('Lê o código da versão atual de um artifact para editar. HTML pequeno volta inline; arquivo grande ou bundle volta como link de download (curl -o). Depois de editar, publique com update_artifact.')]
#[IsReadOnly]
class GetArtifactSourceTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'artifact' => $schema->string()->description('Id (32 caracteres) ou URL do artifact.')->required(),
        ];
    }

    public function handle(Request $request, ContentGateway $gateway): Response|ResponseFactory
    {
        $request->validate(['artifact' => ['required', 'string']]);

        $artifact = $this->findArtifact($request->get('artifact'));

        if (! $artifact?->currentVersion || $this->user($request)->cannot('view', $artifact)) {
            return Response::error('Artifact não encontrado.');
        }

        $version = $artifact->currentVersion;
        $download = URL::temporarySignedRoute('artifacts.download', now()->addMinutes(15), ['artifact' => $artifact->uuid]);
        $data = $this->artifactData($artifact) + ['download_url' => $download];

        if ($version->kind === ArtifactKind::Html && $version->size_bytes <= (int) config('ward.artifacts.mcp_source_inline_max_bytes')) {
            $html = Storage::disk(config('ward.artifacts.disk'))->get($version->filePath());

            return Response::make([
                Response::text("Código da versão {$version->number} de \"{$artifact->title}\" ({$artifact->url()}):"),
                Response::text($html),
            ])->withStructuredContent($data);
        }

        $what = $version->kind === ArtifactKind::Bundle ? 'um .zip com index.html + assets' : 'o .html';

        return Response::make(Response::text(
            "O artifact é grande demais para vir inline. Baixe {$what} (link válido por 15 min):\n\n".
            "curl -sS -o {$artifact->slug}".($version->kind === ArtifactKind::Bundle ? '.zip' : '.html')." \"{$download}\"\n\n".
            "Visualização direta do HTML: {$gateway->url($version)}"
        ))->withStructuredContent($data);
    }
}
