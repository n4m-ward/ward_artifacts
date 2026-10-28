<?php

namespace App\Mcp\Tools;

use App\Models\Artifact;
use App\Whiteboards\WhiteboardComments;
use App\Whiteboards\WhiteboardPresence;
use App\Whiteboards\WhiteboardSession;
use App\Whiteboards\WhiteboardSummary;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_whiteboard')]
#[Description('Lê um whiteboard: elementos (id, tipo, texto, cor, posição, frame, setas de/para), frames, quem está no quadro e o que cada um seleciona, comentários abertos, votação e um lugar livre para desenhar. Quadro grande vem resumido: peça um frame ou uma região. Leia antes de mudar algo.')]
class GetWhiteboardTool extends WhiteboardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'whiteboard' => $schema->string()->description('Id (32 caracteres) ou URL do quadro.')->required(),
            'frame' => $schema->string()->description('Só os elementos deste frame (id).'),
            'region' => $schema->object([
                'x' => $schema->number()->required(), 'y' => $schema->number()->required(),
                'w' => $schema->number()->required(), 'h' => $schema->number()->required(),
            ])->description('Só os elementos nesta área do quadro.'),
        ];
    }

    public function handle(Request $request, WhiteboardSummary $summary, WhiteboardPresence $presence, WhiteboardComments $comments, WhiteboardSession $session): Response|ResponseFactory
    {
        $request->validate(['whiteboard' => ['required', 'string'], 'frame' => ['nullable', 'string'], 'region' => ['nullable', 'array']]);

        return $this->withBoard($request, $request->get('whiteboard'), false, function (Artifact $board) use ($request, $summary, $presence, $comments, $session) {
            $region = $request->get('region');
            $data = $summary->read($board, $request->get('frame'), is_array($region) && isset($region['x'], $region['y'], $region['w'], $region['h'])
                ? array_map('floatval', array_intersect_key($region, array_flip(['x', 'y', 'w', 'h']))) : null);

            $data += array_filter([
                'people' => $presence->viewing($board),
                'comments' => array_map(fn ($thread) => array_filter([
                    'number' => $thread['number'],
                    'element' => $thread['element'],
                    'x' => $thread['x'],
                    'y' => $thread['y'],
                    'messages' => array_map(fn ($message) => ['author' => $message['author'], 'body' => $message['body']], $thread['messages']),
                ], fn ($value) => $value !== null), $comments->threads($board, openOnly: true)),
                'session' => $session->state($board),
                'can_edit' => $this->user($request)->can('edit', $board),
            ], fn ($value) => $value !== [] && $value !== null);

            if ($this->user($request)->can('edit', $board)) {
                $this->showWork($request, $board, 'reading', []);
            }

            $preview = $this->previewUrl($this->user($request), $board);

            return Response::make(Response::text(
                "Whiteboard \"{$board->title}\" ({$data['count']} elementos): {$board->url()}\n".
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n".
                $this->previewHint($preview)
            ))->withStructuredContent(['whiteboard' => $board->uuid, 'title' => $board->title, 'url' => $board->url(), 'preview_url' => $preview] + $data);
        });
    }
}
