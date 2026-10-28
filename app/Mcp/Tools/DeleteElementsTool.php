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

#[Name('delete_elements')]
#[Description('Apaga elementos de um whiteboard pelo id. Apagar um frame apaga o que está dentro; setas ligadas ficam soltas. Quem está com o quadro aberto pode desfazer restaurando uma versão.')]
class DeleteElementsTool extends WhiteboardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'whiteboard' => $schema->string()->description('Id (32 caracteres) ou URL do quadro.')->required(),
            'ids' => $schema->array()->items($schema->string())->description('Ids dos elementos.')->required(),
        ];
    }

    public function handle(Request $request, WhiteboardEditor $editor): Response|ResponseFactory
    {
        $request->validate(['whiteboard' => ['required', 'string'], 'ids' => ['required', 'array', 'min:1', 'max:1000'], 'ids.*' => ['string']]);

        return $this->withBoard($request, $request->get('whiteboard'), true, function (Artifact $board) use ($request, $editor) {
            $result = $editor->delete($board, $this->user($request), array_values($request->get('ids')));
            $this->showWork($request, $board, 'editing', []);

            return $this->result($board, count($result['ids']).' elemento(s) apagado(s).', ['ids' => $result['ids'], 'seq' => $result['seq']]);
        });
    }
}
