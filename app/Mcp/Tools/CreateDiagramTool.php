<?php

namespace App\Mcp\Tools;

use App\Models\Artifact;
use App\Whiteboards\DiagramLayout;
use App\Whiteboards\ElementFactory;
use App\Whiteboards\WhiteboardEditor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_diagram')]
#[Description('Desenha um diagrama num whiteboard a partir só de nós e ligações; o servidor calcula as posições. kind: flowchart (em camadas, direction TB ou LR), tree (árvore para a direita), org (organograma de cima para baixo), mindmap (raiz no centro, ramos dos dois lados) ou grid (post-its em grade). Tudo fica editável. Opcional: dentro de um frame novo com título.')]
class CreateDiagramTool extends WhiteboardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'whiteboard' => $schema->string()->description('Id (32 caracteres) ou URL do quadro.')->required(),
            'kind' => $schema->string()->enum(DiagramLayout::KINDS)->required(),
            'nodes' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(),
                'text' => $schema->string()->required(),
                'shape' => $schema->string()->enum(ElementFactory::SHAPES)->description('Padrão: rectangle (mindmap: a raiz é ellipse; grid: sticky). Decisão: diamond; início/fim: ellipse.'),
                'color' => $schema->string()->description('Fundo: '.implode(', ', array_keys(ElementFactory::STICKY_COLORS)).' ou #hex.'),
                'link' => $schema->string(),
            ]))->description('Nós (até 300). No mindmap o primeiro é a raiz.')->required(),
            'edges' => $schema->array()->items($schema->object([
                'from' => $schema->string()->required(),
                'to' => $schema->string()->required(),
                'label' => $schema->string(),
                'style' => $schema->string()->enum(['solid', 'dashed', 'dotted']),
            ]))->description('Ligações entre nós.'),
            'direction' => $schema->string()->enum(['TB', 'LR'])->description('flowchart: de cima para baixo (TB, padrão) ou da esquerda para a direita (LR).'),
            'frame' => $schema->string()->max(120)->description('Título de um frame novo em volta do diagrama (opcional).'),
            'x' => $schema->number()->description('Canto superior esquerdo (sem x/y: um lugar livre).'),
            'y' => $schema->number(),
            'message' => $schema->string()->max(140),
        ];
    }

    public function handle(Request $request, WhiteboardEditor $editor): Response|ResponseFactory
    {
        $request->validate([
            'whiteboard' => ['required', 'string'],
            'kind' => ['required', 'in:'.implode(',', DiagramLayout::KINDS)],
            'nodes' => ['required', 'array', 'min:1', 'max:300'],
            'edges' => ['nullable', 'array', 'max:600'],
            'direction' => ['nullable', 'in:TB,LR'],
            'frame' => ['nullable', 'string', 'max:120'],
            'x' => ['nullable', 'numeric'],
            'y' => ['nullable', 'numeric'],
            'message' => ['nullable', 'string', 'max:140'],
        ]);

        return $this->withBoard($request, $request->get('whiteboard'), true, function (Artifact $board) use ($request, $editor) {
            $result = $editor->diagram(
                $board,
                $this->user($request),
                $request->get('kind'),
                array_values((array) $request->get('nodes')),
                array_values((array) $request->get('edges', [])),
                $request->get('direction', 'TB'),
                $request->get('frame'),
                $request->filled('x') ? (float) $request->get('x') : null,
                $request->filled('y') ? (float) $request->get('y') : null,
            );
            $this->showWork($request, $board, 'editing', $result['frame'] ? [$result['frame']] : $result['ids'], $request->get('message'));

            return $this->result($board, 'Diagrama '.$request->get('kind').' criado com '.count($request->get('nodes')).' nó(s)'.($result['frame'] ? " no frame {$result['frame']}" : '').'.', [
                'ids' => $result['ids'], 'frame' => $result['frame'], 'seq' => $result['seq'],
            ]);
        });
    }
}
