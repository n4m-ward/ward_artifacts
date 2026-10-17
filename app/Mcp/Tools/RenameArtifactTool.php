<?php

namespace App\Mcp\Tools;

use App\Actions\Artifacts\RenameArtifact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('rename_artifact')]
#[Description('Troca o título de um artifact. A URL muda junto, e a antiga continua redirecionando.')]
#[IsIdempotent]
class RenameArtifactTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'artifact' => $schema->string()->description('Id (32 caracteres) ou URL do artifact.')->required(),
            'title' => $schema->string()->max(200)->description('Novo título.')->required(),
        ];
    }

    public function handle(Request $request, RenameArtifact $rename): Response|ResponseFactory
    {
        $request->validate(['artifact' => ['required', 'string'], 'title' => ['required', 'string', 'max:200']]);

        $user = $this->user($request);
        $artifact = $this->findArtifact($request->get('artifact'));

        if (! $artifact || $user->cannot('view', $artifact)) {
            return Response::error('Artifact não encontrado.');
        }

        if ($user->cannot('update', $artifact)) {
            return Response::error('Só o dono do artifact ou um admin pode renomear.');
        }

        $rename->handle($artifact, $request->get('title'));

        return Response::make(Response::text("Renomeado: {$artifact->url()}"))
            ->withStructuredContent($this->artifactData($artifact));
    }
}
