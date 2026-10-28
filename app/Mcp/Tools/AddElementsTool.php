<?php

namespace App\Mcp\Tools;

use App\Models\Artifact;
use App\Whiteboards\WhiteboardEditor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_elements')]
#[Description('Desenha num whiteboard: post-its (sticky), retângulos, elipses, losangos, textos, setas presas a elementos (from/to, com label), linhas, frames (com children) e imagens (src data:). Ids seus ligam setas e frames no mesmo pedido. Sem x/y os itens vão lado a lado num lugar livre; com place, x/y viram deslocamentos a partir de right_of/below de um elemento, inside de um frame, at {x,y} ou "grid". Para diagramas com layout automático prefira create_diagram.')]
class AddElementsTool extends WhiteboardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'whiteboard' => $schema->string()->description('Id (32 caracteres) ou URL do quadro.')->required(),
            'elements' => $schema->array()->items($this->itemSchema($schema))->description('Itens a criar (até 500).')->required(),
            'place' => $schema->object([
                'right_of' => $schema->string()->description('Id de um elemento: os itens começam à direita dele.'),
                'below' => $schema->string()->description('Id de um elemento: os itens começam abaixo dele.'),
                'inside' => $schema->string()->description('Id de um frame: os itens entram nele (o frame cresce se precisar).'),
                'at' => $schema->object(['x' => $schema->number()->required(), 'y' => $schema->number()->required()])->description('Ponto do quadro.'),
                'grid' => $schema->boolean()->description('Arruma os itens em grade.'),
            ])->description('Onde pôr (opcional).'),
            'message' => $schema->string()->max(140)->description('Frase curta para quem acompanha (ex.: "Ideias da retro").'),
        ];
    }

    public function handle(Request $request, WhiteboardEditor $editor): Response|ResponseFactory
    {
        $request->validate(['whiteboard' => ['required', 'string'], 'elements' => ['required', 'array', 'min:1', 'max:500'], 'place' => ['nullable', 'array'], 'message' => ['nullable', 'string', 'max:140']]);

        return $this->withBoard($request, $request->get('whiteboard'), true, function (Artifact $board) use ($request, $editor) {
            $place = $request->get('place');
            $result = $editor->add($board, $this->user($request), array_values((array) $request->get('elements')), is_array($place) && $place !== [] ? $place : null);
            $this->showWork($request, $board, 'editing', $result['ids'], $request->get('message'));

            return $this->result($board, count($result['ids']).' elemento(s) criado(s): '.implode(', ', $result['ids']).'.', ['ids' => $result['ids'], 'seq' => $result['seq']]);
        });
    }
}
