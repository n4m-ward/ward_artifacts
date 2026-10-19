<?php

namespace App\Mcp\Tools;

use App\Designs\BoardElementFinder;
use App\Designs\BoardRenderer;
use App\Designs\DesignPresence;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('show_in_canvas')]
#[Description('Conduz quem está vendo o canvas do design (página, preview_url, MCP App): a câmera vai até a tela, um cursor com o seu nome aponta o elemento, e o Play abre e clica como uma pessoa. Use para mostrar o que você está fazendo e para TESTAR o protótipo: action "click" executa o clique no Play de quem está vendo e devolve em que tela o Play ficou e se houve erro. Ações: focus (câmera na tela), fit (tudo), point (aponta um elemento), play (abre o Play na tela), click (clica no elemento dentro do Play), back (volta no Play), close (fecha o Play).')]
class ShowInCanvasTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'action' => $schema->string()->enum(['focus', 'fit', 'point', 'play', 'click', 'back', 'close'])->description('O que mostrar ou fazer.')->required(),
            'board' => $schema->string()->description('Nome do artboard (obrigatório em focus, point, play e click). Em click, a tela onde o elemento está.'),
            'element' => $schema->string()->description('Em point e click: o texto visível do elemento ("Pagar") ou um seletor CSS ("#pagar", "nav a:last-child").'),
            'message' => $schema->string()->max(140)->description('Frase curta ao lado do cursor para quem acompanha (ex.: "Testando o botão Pagar").'),
        ];
    }

    public function handle(Request $request, DesignPresence $presence, BoardRenderer $renderer, BoardElementFinder $finder): Response|ResponseFactory
    {
        $validated = $request->validate([
            'design' => ['required', 'string'],
            'action' => ['required', 'in:focus,fit,point,play,click,back,close'],
            'board' => ['nullable', 'string', 'max:60'],
            'element' => ['nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:140'],
        ]);

        $design = $this->findDesign($request, $validated['design']);
        if ($design instanceof Response) {
            return $design;
        }

        if ($error = $this->cannotEdit($request, $design)) {
            return $error;
        }

        $action = $validated['action'];
        $boardName = $validated['board'] ?? null;
        $element = $validated['element'] ?? null;

        if (in_array($action, ['focus', 'point', 'play', 'click'], true) && blank($boardName)) {
            return Response::error("A ação \"{$action}\" precisa de board (o nome do artboard).");
        }
        if (in_array($action, ['point', 'click'], true) && blank($element)) {
            return Response::error("A ação \"{$action}\" precisa de element (texto visível ou seletor CSS).");
        }

        $board = $boardName !== null ? $design->boards()->where('name', $boardName)->first() : null;
        if ($boardName !== null && ! $board) {
            return Response::error("Artboard não encontrado: \"{$boardName}\".");
        }

        // O que o HTML diz do elemento (vale mesmo sem ninguém vendo).
        $static = $board && $element ? $finder->find($renderer->render($design, $board), $element) : null;
        if (in_array($action, ['point', 'click'], true) && ! $static) {
            return Response::error("Não achei \"{$element}\" no artboard \"{$board->name}\". Use o texto visível do elemento ou um seletor CSS (veja get_artboard_source).");
        }

        $sent = $presence->push($design, DesignPresence::actorFor($this->user($request)), [
            'action' => $action,
            'board' => $board?->name,
            'element' => $element,
            'message' => $validated['message'] ?? null,
        ]);

        $watching = $presence->hasViewers($design);
        $waits = in_array($action, ['point', 'play', 'click', 'back', 'close'], true);
        $result = $watching && $waits ? $presence->await($design, $sent['seq'], (float) config('ward.designs.presence_wait_seconds', 8)) : null;

        return Response::make(Response::text($this->describe($action, $board?->name, $static, $watching, $waits, $result)))
            ->withStructuredContent(array_filter([
                'design' => $design->uuid,
                'action' => $action,
                'seq' => $sent['seq'],
                'watching' => $watching,
                'element' => $static,
                'result' => $result,
            ], fn ($value) => $value !== null));
    }

    /**
     * @param  array<string, mixed>|null  $static
     * @param  array<string, mixed>|null  $result
     */
    private function describe(string $action, ?string $board, ?array $static, bool $watching, bool $waits, ?array $result): string
    {
        $label = $static ? "<{$static['tag']}> \"{$static['text']}\"" : '';
        $link = $static['link'] ?? null;
        $expected = match (true) {
            $link === null => 'não tem ligação do protótipo (o clique só roda o JS da própria tela, se houver)',
            isset($link['close']) => 'fecha a tela de cima',
            isset($link['back']) => 'volta à tela anterior',
            isset($link['overlay']) => "abre \"{$link['to']}\" por cima",
            default => "leva a \"{$link['to']}\"",
        };

        $text = match ($action) {
            'focus' => "Câmera em \"{$board}\".",
            'fit' => 'Câmera mostrando o canvas inteiro.',
            'point' => "Cursor apontando {$label} em \"{$board}\". Pelo HTML, ele {$expected}.",
            'play' => "Play aberto em \"{$board}\".",
            'click' => "Clique em {$label} no Play de \"{$board}\". Pelo HTML, ele {$expected}.",
            'back' => 'Voltar no Play.',
            'close' => 'Play fechado.',
        };

        if (! $watching) {
            return $text."\nNinguém está com o canvas aberto agora: nada foi visto nem clicado de verdade."
                .($waits ? ' Para testar, abra o canvas (open_design, ou o preview_url no seu navegador) e repita.' : '');
        }

        if (! $waits) {
            return $text;
        }

        if ($result === null) {
            return $text."\nO canvas aberto não respondeu a tempo (aba em segundo plano ou a pessoa pausou \"seguir a IA\"). Sem confirmação visual.";
        }

        if (in_array($action, ['point', 'click'], true) && ! ($result['found'] ?? false)) {
            return $text."\nNo canvas, o elemento não foi encontrado na tela aberta".(isset($result['screen']) ? " (o Play está em \"{$result['screen']}\")" : '').'.';
        }

        $where = isset($result['screen']) ? "O Play está em \"{$result['screen']}\"" : 'O Play está fechado';
        $where .= ($result['overlays'] ?? []) !== [] ? ', com "'.implode('", "', $result['overlays']).'" por cima' : '';
        $errors = ($result['errors'] ?? []) !== [] ? "\nErros de script: ".implode(' | ', $result['errors']) : "\nSem erros de script.";

        return $text."\nResultado no canvas: {$where}.".(isset($result['note']) ? " {$result['note']}" : '').$errors;
    }
}
