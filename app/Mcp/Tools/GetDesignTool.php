<?php

namespace App\Mcp\Tools;

use App\Designs\DesignCanvas;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_design')]
#[Description('Mostra o canvas de um design: artboards (nome, tipo, posição, tamanho, revisão), notas e a URL. Não traz o HTML: use get_artboard_source para isso. Também devolve preview_url (uso único, sem login) para conferir no seu navegador.')]
#[IsReadOnly]
class GetDesignTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
        ];
    }

    public function handle(Request $request, DesignCanvas $canvas): Response|ResponseFactory
    {
        $request->validate(['design' => ['required', 'string']]);

        $design = $this->findDesign($request, $request->get('design'));
        if ($design instanceof Response) {
            return $design;
        }

        $data = $canvas->toArray($design);
        $preview = $this->previewUrl($this->user($request), $design);

        $lines = collect($data['boards'])->map(fn ($b) => "- {$b['name']} ({$b['kind']}, {$b['w']}×{$b['h']} em {$b['x']},{$b['y']}, rev {$b['revision']})".($b['interactive'] ? ' interativo' : ''));

        return Response::make(Response::text(
            "\"{$design->title}\": ".count($data['boards'])." artboard(s), revisão {$data['revision']}. {$design->url()}\n".
            ($lines->isEmpty() ? 'Ainda sem artboards.' : $lines->implode("\n"))."\n\n".$this->previewHint($preview)
        ))->withStructuredContent($this->artifactData($design) + ['canvas' => $data, 'preview_url' => $preview]);
    }
}
