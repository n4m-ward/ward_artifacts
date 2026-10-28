<?php

namespace App\Mcp\Tools;

use App\Actions\Designs\DesignComments;
use App\Designs\InvalidDesign;
use App\Whiteboards\InvalidWhiteboard;
use App\Whiteboards\WhiteboardComments;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('resolve_comment')]
#[Description('Responde a um comentário do canvas ou do whiteboard (get_design e get_whiteboard listam os abertos com número, ex.: #3) e, por padrão, marca como resolvido. Use depois de aplicar a mudança pedida, dizendo em uma frase o que fez. Para só responder (pergunta, dúvida), mande resolve: false.')]
class ResolveCommentTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design ou do whiteboard.')->required(),
            'comment' => $schema->integer()->description('Número do comentário (o #3 vira 3).')->required(),
            'message' => $schema->string()->description('Resposta curta para a pessoa (o que mudou, ou a pergunta).'),
            'resolve' => $schema->boolean()->description('Marcar como resolvido (padrão: true).'),
        ];
    }

    public function handle(Request $request, DesignComments $comments, WhiteboardComments $boardComments): Response|ResponseFactory
    {
        $validated = $request->validate([
            'design' => ['required', 'string'],
            'comment' => ['required'],
            'message' => ['nullable', 'string', 'max:'.DesignComments::MAX_BODY],
            'resolve' => ['nullable', 'boolean'],
        ]);

        if ($this->findArtifact($validated['design'])?->isWhiteboard()) {
            return $this->whiteboard($request, $validated, $boardComments);
        }

        $design = $this->findDesign($request, $validated['design']);
        if ($design instanceof Response) {
            return $design;
        }

        $thread = $comments->find($design, $validated['comment']);

        if (! $thread) {
            return Response::error("Comentário #{$validated['comment']} não encontrado neste design.");
        }

        $resolve = $validated['resolve'] ?? true;

        if ($resolve && $error = $this->cannotEdit($request, $design)) {
            return $error;
        }

        try {
            if (filled($validated['message'] ?? null)) {
                $comments->reply($thread, $this->user($request), $validated['message'], 'ai');
            }

            if ($resolve) {
                $comments->resolve($thread, $this->user($request));
            }
        } catch (InvalidDesign $e) {
            return Response::error($e->getMessage());
        }

        return Response::text($resolve
            ? "Comentário #{$thread->number} resolvido. Design: {$design->url()}"
            : "Resposta enviada no comentário #{$thread->number}. Design: {$design->url()}");
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function whiteboard(Request $request, array $validated, WhiteboardComments $comments): Response|ResponseFactory
    {
        $board = $this->findWhiteboard($request, $validated['design']);
        if ($board instanceof Response) {
            return $board;
        }

        $number = (int) ltrim((string) $validated['comment'], '#');
        $thread = $board->comments()->whereNull('parent_id')->where('number', $number)->first();

        if (! $thread) {
            return Response::error("Comentário #{$number} não encontrado neste quadro.");
        }

        $resolve = $validated['resolve'] ?? true;

        if ($resolve && $error = $this->cannotEditWhiteboard($request, $board)) {
            return $error;
        }

        try {
            if (filled($validated['message'] ?? null)) {
                $comments->reply($thread, $this->user($request), $validated['message'], 'ai');
            }

            if ($resolve) {
                $comments->resolve($thread, $this->user($request));
            }
        } catch (InvalidWhiteboard $e) {
            return Response::error($e->getMessage());
        }

        return Response::text($resolve
            ? "Comentário #{$thread->number} resolvido. Quadro: {$board->url()}"
            : "Resposta enviada no comentário #{$thread->number}. Quadro: {$board->url()}");
    }
}
