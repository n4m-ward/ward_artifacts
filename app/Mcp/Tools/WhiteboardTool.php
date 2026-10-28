<?php

namespace App\Mcp\Tools;

use App\Designs\DesignPresence;
use App\Models\Artifact;
use App\Whiteboards\ElementFactory;
use App\Whiteboards\InvalidWhiteboard;
use App\Whiteboards\WhiteboardPresence;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Base das ferramentas que mexem num whiteboard: acha o quadro, confere a
 * permissão de edição, mostra a IA trabalhando (cursor e câmera de quem está
 * vendo) e transforma InvalidWhiteboard em erro para a IA corrigir.
 */
abstract class WhiteboardTool extends WardTool
{
    /**
     * @param  Closure(Artifact): (Response|ResponseFactory)  $callback
     */
    protected function withBoard(Request $request, ?string $reference, bool $edit, Closure $callback): Response|ResponseFactory
    {
        $board = $this->findWhiteboard($request, $reference);

        if ($board instanceof Response) {
            return $board;
        }

        if ($edit && $error = $this->cannotEditWhiteboard($request, $board)) {
            return $error;
        }

        try {
            return $callback($board);
        } catch (InvalidWhiteboard $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * A IA aparece no quadro de quem está vendo, sobre os elementos que mexeu.
     *
     * @param  list<string>  $elements
     */
    protected function showWork(Request $request, Artifact $board, string $action, array $elements, ?string $message = null): void
    {
        app(WhiteboardPresence::class)->pushAi($board, DesignPresence::actorFor($this->user($request)), [
            'action' => $action,
            'elements' => array_values(array_filter($elements)),
            'message' => $message,
        ]);
    }

    /**
     * Esquema de um item no formato simples (o mesmo de add_elements e templates).
     */
    protected function itemSchema(JsonSchema $schema): mixed
    {
        return $schema->object([
            'id' => $schema->string()->description('Id seu (letras, números, _ e -, até 48), para ligar setas e frames no mesmo pedido. Sem id, um é gerado.'),
            'type' => $schema->string()->enum(['sticky', 'rectangle', 'ellipse', 'diamond', 'text', 'arrow', 'line', 'frame', 'image'])->description('sticky = post-it.')->required(),
            'text' => $schema->string()->description('Texto (post-it, forma ou texto solto).'),
            'x' => $schema->number(),
            'y' => $schema->number(),
            'width' => $schema->number(),
            'height' => $schema->number(),
            'color' => $schema->string()->description('Post-it/forma: fundo ('.implode(', ', array_keys(ElementFactory::STICKY_COLORS)).' ou #hex). Texto/seta: cor do traço ('.implode(', ', array_keys(ElementFactory::STROKE_COLORS)).').'),
            'fill' => $schema->string()->description('Fundo da forma (nome da paleta ou #hex).'),
            'stroke' => $schema->string()->description('Cor da borda/traço.'),
            'style' => $schema->string()->enum(['solid', 'dashed', 'dotted']),
            'fontSize' => $schema->integer()->description('Tamanho do texto (padrão 20).'),
            'size' => $schema->string()->enum(['s', 'm', 'l', 'xl'])->description('Atalho de tamanho do texto.'),
            'align' => $schema->string()->enum(['left', 'center', 'right'])->description('Alinhamento de um texto solto.'),
            'from' => $schema->string()->description('Seta/linha: id do elemento de origem (fica presa a ele).'),
            'to' => $schema->string()->description('Seta/linha: id do elemento de destino.'),
            'x2' => $schema->number()->description('Seta solta: ponto final.'),
            'y2' => $schema->number(),
            'label' => $schema->string()->description('Seta: texto no meio.'),
            'route' => $schema->string()->enum(['straight', 'curved', 'elbow'])->description('Seta: reta (padrão), curva ou em cotovelo.'),
            'startArrow' => $schema->string()->description('Seta: ponta no início (arrow, triangle, dot, circle, diamond, bar ou none).'),
            'endArrow' => $schema->string()->description('Seta: ponta no fim (padrão arrow; none para linha sem ponta).'),
            'name' => $schema->string()->description('Frame: título.'),
            'children' => $schema->array()->items($schema->string())->description('Frame: ids dos elementos que ficam dentro (sem width/height, o frame os envolve).'),
            'src' => $schema->string()->description('Imagem: data:image/png;base64,... (até 4 MB).'),
            'link' => $schema->string()->description('Link http(s) no elemento.'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function result(Artifact $board, string $text, array $data): ResponseFactory
    {
        return Response::make(Response::text($text."\nQuadro: {$board->url()}"))
            ->withStructuredContent(['whiteboard' => $board->uuid] + $data);
    }
}
