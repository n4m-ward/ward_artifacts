<?php

namespace App\Mcp\Tools;

use App\Designs\DesignCanvas;
use App\Designs\DesignState;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_design')]
#[Description('Mostra o canvas de um design: artboards (nome, tipo, posição, tamanho, revisão), notas, comentários abertos (com o elemento a que se referem), o que a pessoa está vendo agora (artboard e elemento selecionados, artboards visíveis, erros de script) e a URL. Não traz o HTML: use get_artboard_source. Também devolve preview_url (uso único, sem login) para conferir no seu navegador.')]
#[IsReadOnly]
class GetDesignTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
        ];
    }

    public function handle(Request $request, DesignCanvas $canvas, DesignState $state): Response|ResponseFactory
    {
        $request->validate(['design' => ['required', 'string']]);

        $design = $this->findDesign($request, $request->get('design'));
        if ($design instanceof Response) {
            return $design;
        }

        $data = $canvas->toArray($design);
        $preview = $this->previewUrl($this->user($request), $design);
        $viewing = $state->viewing($design, $this->user($request));
        $comments = $state->comments($design, openOnly: true);
        $assets = $design->assets()->orderBy('id')->get()->map->toCanvas()->values()->all();

        $pageNames = collect($data['pages'])->pluck('name', 'key');
        $lines = collect($data['boards'])->map(fn ($b) => "- {$b['name']} ({$b['kind']}, {$b['w']}×{$b['h']} em {$b['x']},{$b['y']}, rev {$b['revision']})"
            .($b['interactive'] ? ' interativo' : '')
            .($pageNames->isNotEmpty() ? ', página "'.($pageNames[$b['page']] ?? $pageNames->first()).'"' : '')
            .($b['includes'] ? ', usa '.implode(', ', $b['includes']) : ''));

        return Response::make(Response::text(
            "\"{$design->title}\": ".count($data['boards'])." artboard(s), revisão {$data['revision']}. {$design->url()}\n".
            ($lines->isEmpty() ? 'Ainda sem artboards.' : $lines->implode("\n"))."\n".
            ($pageNames->isNotEmpty() ? 'Páginas: '.$pageNames->map(fn ($name, $key) => "{$name} ({$key})")->implode(', ').".\n" : '').
            ($data['tokens'] ? "Tokens do design (:root de todos os artboards): {$data['tokens']}\n" : 'Sem tokens: defina cores e fontes compartilhadas com set_design_tokens.'."\n").
            ($assets ? 'Imagens do design (use no HTML como "assets/{nome}"): '.collect($assets)->map(fn ($a) => $a['name'].($a['width'] ? " {$a['width']}×{$a['height']}" : ''))->implode(', ').".\n" : '').
            $this->describeViewing($viewing).
            $this->describeComments($comments)."\n".
            $this->previewHint($preview)
        ))->withStructuredContent($this->artifactData($design) + [
            'canvas' => $data,
            'viewing' => $viewing,
            'comments' => $comments,
            'assets' => $assets,
            'preview_url' => $preview,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $viewing
     */
    private function describeViewing(?array $viewing): string
    {
        if (! $viewing) {
            return '';
        }

        $text = '';

        if (isset($viewing['board'])) {
            $text .= "A pessoa está com o artboard \"{$viewing['board']}\" selecionado no canvas";
            $element = $viewing['element'] ?? null;
            $text .= $element
                ? ", no elemento <{$element['tag']}> (caminho {$element['path']} a partir do <body>)".(empty($element['text']) ? '' : ": \"{$element['text']}\"").".\n"
                : ".\n";
        }

        if (! empty($viewing['visible'])) {
            $text .= 'Artboards visíveis na tela dela: '.implode(', ', $viewing['visible']).".\n";
        }

        foreach ($viewing['errors'] ?? [] as $error) {
            $text .= "Erro de script no artboard \"{$error['board']}\": {$error['message']}\n";
        }

        return $text;
    }

    /**
     * @param  list<array<string, mixed>>  $comments
     */
    private function describeComments(array $comments): string
    {
        if ($comments === []) {
            return '';
        }

        $text = count($comments)." comentário(s) aberto(s) (responda e resolva com resolve_comment):\n";

        foreach (array_reverse($comments) as $thread) {
            $where = $thread['board_name']
                ? "artboard \"{$thread['board_name']}\"".(isset($thread['anchor']['tag']) ? ", elemento <{$thread['anchor']['tag']}> (caminho {$thread['anchor']['path']})".(empty($thread['anchor']['text']) ? '' : " \"{$thread['anchor']['text']}\"") : '')
                : "canvas em {$thread['x']},{$thread['y']}";

            $text .= "#{$thread['number']} em {$where}:\n";

            foreach ($thread['messages'] as $message) {
                $who = $message['source'] === 'ai' ? "{$message['author']} (IA)" : $message['author'];
                $text .= "  {$who}: {$message['body']}\n";
            }
        }

        return $text;
    }
}
