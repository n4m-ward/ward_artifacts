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

#[Name('update_elements')]
#[Description('Muda elementos de um whiteboard pelo id (veja get_whiteboard): texto, cor, posição (x/y ou dx/dy; o texto preso e as setas acompanham), tamanho, frame (id ou null para tirar), nome do frame, estilo, link, pontas e ligações (from/to) de setas, rótulo da seta.')]
class UpdateElementsTool extends WhiteboardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'whiteboard' => $schema->string()->description('Id (32 caracteres) ou URL do quadro.')->required(),
            'changes' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(),
                'text' => $schema->string(),
                'label' => $schema->string()->description('Rótulo de uma seta.'),
                'color' => $schema->string(),
                'fill' => $schema->string(),
                'stroke' => $schema->string(),
                'style' => $schema->string()->enum(['solid', 'dashed', 'dotted']),
                'x' => $schema->number(), 'y' => $schema->number(),
                'dx' => $schema->number(), 'dy' => $schema->number(),
                'width' => $schema->number(), 'height' => $schema->number(),
                'frame' => $schema->string()->description('Id do frame, ou "" para tirar do frame.'),
                'name' => $schema->string()->description('Nome de um frame.'),
                'fontSize' => $schema->integer(),
                'link' => $schema->string(),
                'from' => $schema->string(), 'to' => $schema->string(),
                'startArrow' => $schema->string(), 'endArrow' => $schema->string(),
            ]))->description('Uma mudança por elemento.')->required(),
            'message' => $schema->string()->max(140),
        ];
    }

    public function handle(Request $request, WhiteboardEditor $editor): Response|ResponseFactory
    {
        $request->validate(['whiteboard' => ['required', 'string'], 'changes' => ['required', 'array', 'min:1', 'max:500'], 'message' => ['nullable', 'string', 'max:140']]);

        return $this->withBoard($request, $request->get('whiteboard'), true, function (Artifact $board) use ($request, $editor) {
            $result = $editor->update($board, $this->user($request), array_values((array) $request->get('changes')));
            $this->showWork($request, $board, 'editing', $result['ids'], $request->get('message'));

            return $this->result($board, count($result['ids']).' elemento(s) atualizado(s).', ['ids' => $result['ids'], 'seq' => $result['seq']]);
        });
    }
}
