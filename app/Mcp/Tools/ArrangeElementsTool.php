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

#[Name('arrange_elements')]
#[Description('Organiza elementos de um whiteboard: grid (grade), left/center/right/top/middle/bottom (alinhar), horizontal/vertical (distribuir) ou cluster: você diz os grupos (nome + ids) e cada grupo vira um frame com os elementos em grade, como agrupar post-its por tema numa retrospectiva.')]
class ArrangeElementsTool extends WhiteboardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'whiteboard' => $schema->string()->description('Id (32 caracteres) ou URL do quadro.')->required(),
            'mode' => $schema->string()->enum(['grid', 'cluster', 'left', 'center', 'right', 'top', 'middle', 'bottom', 'horizontal', 'vertical'])->required(),
            'ids' => $schema->array()->items($schema->string())->description('Elementos (todos os modos menos cluster).'),
            'groups' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'ids' => $schema->array()->items($schema->string())->required(),
            ]))->description('cluster: os grupos.'),
            'message' => $schema->string()->max(140),
        ];
    }

    public function handle(Request $request, WhiteboardEditor $editor): Response|ResponseFactory
    {
        $request->validate(['whiteboard' => ['required', 'string'], 'mode' => ['required', 'string'], 'ids' => ['nullable', 'array'], 'groups' => ['nullable', 'array'], 'message' => ['nullable', 'string', 'max:140']]);

        return $this->withBoard($request, $request->get('whiteboard'), true, function (Artifact $board) use ($request, $editor) {
            $result = $editor->arrange($board, $this->user($request), array_values(array_map('strval', (array) $request->get('ids', []))), $request->get('mode'), array_values((array) $request->get('groups', [])));
            $this->showWork($request, $board, 'editing', $result['frames'] ?: $result['ids'], $request->get('message'));

            return $this->result($board, count($result['ids']).' elemento(s) organizados ('.$request->get('mode').').'.($result['frames'] ? ' Frames: '.implode(', ', $result['frames']).'.' : ''), $result);
        });
    }
}
