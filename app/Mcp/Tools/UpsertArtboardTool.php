<?php

namespace App\Mcp\Tools;

use App\Actions\Designs\PublishComponent;
use App\Actions\Designs\UpsertArtboard;
use App\Designs\DesignCanvas;
use App\Designs\DesignLayoutCheck;
use App\Designs\DesignPresence;
use App\Designs\InvalidDesign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('upsert_artboard')]
#[Description('Cria ou atualiza um artboard de um design, pelo nome. Artboard novo: mande html, kind, w e h (x/y opcionais: sem eles entra à direita do último). Artboard existente: mande só o que muda (ex.: só html, ou só x/y). O HTML é uma página autocontida; regras em get_design_guide.')]
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
            'page' => $schema->string()->description('Chave da página do canvas (crie com update_layout.pages). Vazio: primeira página.'),
            'description' => $schema->string()->description('Em design system: o que o componente é e quando usar.'),
            'tags' => $schema->string()->description('Em design system: palavras para o filtro, separadas por vírgula (ex.: "botão, ação, primário").'),
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
            'page' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'string', 'max:200'],
        ]);

        $design = $this->findDesign($request, $validated['design']);
        if ($design instanceof Response) {
            return $design;
        }

        if ($error = $this->cannotEdit($request, $design)) {
            return $error;
        }

        try {
            $data = $validated;
            if (! $request->has('page')) {
                unset($data['page']);
            }
            unset($data['description'], $data['tags']);
            $board = $upsert->handle($design, $this->user($request), $data);

            if ($request->has('description') || $request->has('tags')) {
                app(PublishComponent::class)->describe($design, $board, $validated['description'] ?? null, $validated['tags'] ?? null);
            }
        } catch (InvalidDesign $e) {
            return Response::error($e->getMessage());
        }

        $design->refresh();
        $check = app(DesignLayoutCheck::class)->check($design);
        // Quem acompanha o canvas vê a câmera ir até a tela salva.
        app(DesignPresence::class)->push($design, DesignPresence::actorFor($this->user($request)), [
            'action' => 'editing', 'board' => $board->name, 'message' => "Editando {$board->name}",
        ]);

        return Response::make(Response::text(
            "Artboard \"{$board->name}\" salvo (revisão {$board->revision}) em {$board->x},{$board->y}, {$board->w}×{$board->h}. ".
            "Design: {$design->url()}\n".DesignLayoutCheck::describe($check)
        ))->withStructuredContent([
            'design' => $design->uuid,
            'url' => $design->url(),
            'design_revision' => $design->design_revision,
            'board' => $canvas->board($board),
        ] + $check);
    }
}
