<?php

namespace App\Mcp\Tools;

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
#[Description('Lê o código da versão atual de um artifact para editar. HTML pequeno volta inline; arquivo grande ou bundle volta como link de download (curl -o). Também devolve preview_url: link de uso único, sem login, para abrir o artifact no seu navegador. Depois de editar, publique com update_artifact.')]
#[IsReadOnly]
class GetArtifactSourceTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'artifact' => $schema->string()->description('Id (32 caracteres) ou URL do artifact.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate(['artifact' => ['required', 'string']]);

        $artifact = $this->findArtifact($request->get('artifact'));

        if (! $artifact || $this->user($request)->cannot('view', $artifact)) {
            return Response::error('Artifact não encontrado.');
        }

        if ($artifact->isDesign()) {
            return Response::error('Este artifact é um design: use get_design e get_artboard_source.');
        }

        if (! $artifact->currentVersion) {
            return Response::error('Artifact não encontrado.');
        }

        $version = $artifact->currentVersion;
        $download = URL::temporarySignedRoute('artifacts.download', now()->addMinutes(15), ['artifact' => $artifact->uuid]);
        $preview = $this->previewUrl($this->user($request), $artifact);
        $data = $this->artifactData($artifact) + ['download_url' => $download, 'preview_url' => $preview];

        if ($version->kind === ArtifactKind::Html && $version->size_bytes <= (int) config('ward.artifacts.mcp_source_inline_max_bytes')) {
            $html = Storage::disk(config('ward.artifacts.disk'))->get($version->filePath());

            return Response::make([
                Response::text("Código da versão {$version->number} de \"{$artifact->title}\" ({$artifact->url()}):"),
                Response::text($html),
                Response::text($this->previewHint($preview)),
            ])->withStructuredContent($data);
        }

        $what = $version->kind === ArtifactKind::Bundle ? 'um .zip com index.html + assets' : 'o .html';

        return Response::make(Response::text(
            "O artifact é grande demais para vir inline. Baixe {$what} (link válido por 15 min):\n\n".
            "curl -sS -o {$artifact->slug}".($version->kind === ArtifactKind::Bundle ? '.zip' : '.html')." \"{$download}\"\n\n".
            $this->previewHint($preview)
        ))->withStructuredContent($data);
    }
}
