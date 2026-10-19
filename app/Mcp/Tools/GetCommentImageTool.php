<?php

namespace App\Mcp\Tools;

use App\Actions\Designs\DesignComments;
use App\Designs\DesignStorage;
use App\Models\DesignComment;
use App\Models\DesignCommentImage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_comment_image')]
#[Description('Mostra uma imagem anexada a um comentário do canvas (print, referência, foto). get_design diz quantas imagens cada comentário tem (ex.: "#3 … 2 imagens"). Olhe a imagem antes de aplicar o pedido do comentário.')]
#[IsReadOnly]
class GetCommentImageTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'comment' => $schema->integer()->description('Número do comentário (ex.: 3 para #3).')->required(),
            'image' => $schema->integer()->min(1)->description('Qual imagem do tópico, na ordem em que aparecem (1 = a primeira, contando as respostas). Padrão: 1.'),
        ];
    }

    public function handle(Request $request, DesignComments $comments, DesignStorage $storage): Response|ResponseFactory
    {
        $validated = $request->validate([
            'design' => ['required', 'string'],
            'comment' => ['required', 'integer'],
            'image' => ['nullable', 'integer', 'min:1'],
        ]);

        $design = $this->findDesign($request, $validated['design']);
        if ($design instanceof Response) {
            return $design;
        }

        $thread = $comments->find($design, $validated['comment']);
        if (! $thread) {
            return Response::error("Comentário #{$validated['comment']} não encontrado.");
        }

        $images = collect([$thread])->concat($thread->replies()->with('images')->get())
            ->flatMap(fn (DesignComment $message) => $message->images)
            ->values();
        $index = (int) ($validated['image'] ?? 1);
        $image = $images->get($index - 1);

        if (! $image instanceof DesignCommentImage) {
            return Response::error($images->isEmpty()
                ? "O comentário #{$thread->number} não tem imagens."
                : "O comentário #{$thread->number} tem {$images->count()} imagem(ns); peça de 1 a {$images->count()}.");
        }

        $bytes = (string) $storage->disk()->get($image->storagePath($design));

        return Response::make([
            Response::text("Imagem {$index} de {$images->count()} do comentário #{$thread->number}".($image->width ? " ({$image->width}×{$image->height})" : '').':'),
            Response::image(base64_encode($bytes), $image->mime),
        ]);
    }
}
