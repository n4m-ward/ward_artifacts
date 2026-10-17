<?php

namespace App\Mcp\Tools;

use App\Actions\Designs\UpsertArtboard;
use App\Designs\DesignCanvas;
use App\Designs\InvalidDesign;
use App\Mcp\Apps\DesignApp;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\RendersApp;

#[Name('upsert_artboard')]
#[Description('Cria ou atualiza um artboard de um design, pelo nome. Artboard novo: mande html, kind, w e h (x/y opcionais: sem eles entra à direita do último). Artboard existente: mande só o que muda (ex.: só html, ou só x/y). O HTML é uma página autocontida; regras em get_design_guide.')]
#[RendersApp(DesignApp::class)]
class UpsertArtboardTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'name' => $schema->string()->max(60)->description('Nome estável do artboard (ex.: "Home", "Checkout", "Post"). Letras, números, espaço, _ ou -.')->required(),
            'html' => $schema->string()->description('HTML completo e autocontido do artboard (até 5 MB).'),
            'kind' => $schema->string()->enum(['fixed', 'page'])->description('fixed: tamanho exato (post, tela de app). page: página fluida (site, dashboard).'),
            'w' => $schema->integer()->min(40)->max(8000)->description('Largura em px.'),
            'h' => $schema->integer()->min(40)->max(8000)->description('Altura em px (em page, a altura na largura w).'),
            'x' => $schema->integer()->description('Posição no canvas (px).'),
            'y' => $schema->integer()->description('Posição no canvas (px).'),
            'title' => $schema->string()->max(120)->description('Rótulo mostrado acima do artboard (opcional).'),
            'interactive' => $schema->boolean()->description('true se tem controles que funcionam ou links board: entre artboards.'),
        ];
    }

    public function handle(Request $request, UpsertArtboard $upsert, DesignCanvas $canvas): Response|ResponseFactory
    {
        $validated = $request->validate([
            'design' => ['required', 'string'],
            'name' => ['required', 'string', 'max:60'],
            'html' => ['nullable', 'string'],
            'kind' => ['nullable', 'in:fixed,page'],
            'w' => ['nullable', 'integer'],
            'h' => ['nullable', 'integer'],
            'x' => ['nullable', 'integer'],
            'y' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:120'],
            'interactive' => ['nullable', 'boolean'],
        ]);

        $design = $this->findDesign($request, $validated['design']);
        if ($design instanceof Response) {
            return $design;
        }

        if ($error = $this->cannotEdit($request, $design)) {
            return $error;
        }

        try {
            $board = $upsert->handle($design, $this->user($request), $validated);
        } catch (InvalidDesign $e) {
            return Response::error($e->getMessage());
        }

        $design->refresh();

        return Response::make(Response::text(
            "Artboard \"{$board->name}\" salvo (revisão {$board->revision}) em {$board->x},{$board->y}, {$board->w}×{$board->h}. ".
            "Design: {$design->url()}"
        ))->withStructuredContent([
            'design' => $design->uuid,
            'url' => $design->url(),
            'design_revision' => $design->design_revision,
            'board' => $canvas->board($board),
        ]);
    }
}
