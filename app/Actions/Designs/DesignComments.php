<?php

namespace App\Actions\Designs;

use App\Designs\InvalidDesign;
use App\Models\Artifact;
use App\Models\DesignComment;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Comentários do canvas (canvas web e IA pelo MCP). Cada mudança sobe a
 * revisão do design, então quem está com o canvas aberto vê na hora.
 */
class DesignComments extends DesignMutation
{
    public const MAX_BODY = 4000;

    public function __construct(private DesignCommentImages $images) {}

    /**
     * Abre um tópico. Âncora: board (+ anchor do elemento) ou x/y livres no canvas.
     *
     * @param  array{body: string, board?: ?int, anchor?: ?array<string, mixed>, x?: ?int, y?: ?int, images?: list<int>}  $data
     */
    public function open(Artifact $design, User $user, array $data, string $source = 'web'): DesignComment
    {
        $body = $this->body($data['body'] ?? '', ! empty($data['images']));
        $board = isset($data['board']) ? $design->boards()->find($data['board']) : null;

        if (isset($data['board']) && $board === null) {
            throw new InvalidDesign('Este artboard não existe mais.');
        }

        if ($board === null && (! isset($data['x']) || ! isset($data['y']))) {
            throw new InvalidDesign('Um comentário precisa de um artboard ou de um ponto no canvas.');
        }

        return $this->mutate($design, fn () => tap($design->comments()->create([
            'number' => (int) $design->comments()->max('number') + 1,
            'user_id' => $user->id,
            'board_id' => $board?->id,
            'board_name' => $board?->name,
            'anchor' => $board ? $this->anchor($data['anchor'] ?? null) : null,
            'x' => $board ? null : (int) $data['x'],
            'y' => $board ? null : (int) $data['y'],
            'body' => $body,
            'source' => $source,
        ]), fn (DesignComment $comment) => $this->images->attach($design, $user, $comment, $data['images'] ?? [])));
    }

    /**
     * @param  list<int>  $images  imagens enviadas antes (DesignCommentImages::upload)
     */
    public function reply(DesignComment $thread, User $user, string $body, string $source = 'web', array $images = []): DesignComment
    {
        $body = $this->body($body, $images !== []);

        return $this->mutate($thread->artifact, fn () => tap($thread->artifact->comments()->create([
            'parent_id' => $thread->id,
            'user_id' => $user->id,
            'body' => $body,
            'source' => $source,
        ]), fn (DesignComment $comment) => $this->images->attach($thread->artifact, $user, $comment, $images)));
    }

    public function resolve(DesignComment $thread, User $user, bool $resolved = true): void
    {
        $this->mutate($thread->artifact, fn () => $thread->forceFill([
            'resolved_at' => $resolved ? now() : null,
            'resolved_by' => $resolved ? $user->id : null,
        ])->save());
    }

    public function remove(DesignComment $comment): void
    {
        $this->mutate($comment->artifact, function () use ($comment) {
            $this->images->deleteFiles($comment);
            $comment->delete();
        });
    }

    /**
     * Tópico pelo número (#3) ou pelo id.
     */
    public function find(Artifact $design, int|string $reference): ?DesignComment
    {
        $number = (int) ltrim((string) $reference, '#');

        return $design->comments()->whereNull('parent_id')->where('number', $number)->first();
    }

    private function body(string $body, bool $hasImages = false): string
    {
        $body = trim($body);

        // Só imagem também vale (um print colado já diz o que é).
        if ($body === '' && ! $hasImages) {
            throw new InvalidDesign('O comentário está vazio.');
        }

        return Str::limit($body, self::MAX_BODY, '');
    }

    /**
     * Só o que serve para achar o elemento depois (vem do navegador: limitado e sem HTML inteiro).
     *
     * @param  array<string, mixed>|null  $anchor
     * @return array<string, mixed>|null
     */
    private function anchor(?array $anchor): ?array
    {
        if ($anchor === null) {
            return null;
        }

        return array_filter([
            'path' => preg_match('#^\d{1,4}(/\d{1,4}){0,30}$#', (string) ($anchor['path'] ?? '')) ? $anchor['path'] : null,
            'tag' => preg_match('/^[a-z][a-z0-9-]{0,30}$/', (string) ($anchor['tag'] ?? '')) ? $anchor['tag'] : null,
            'text' => isset($anchor['text']) ? Str::limit((string) $anchor['text'], 120, '') : null,
            'html' => isset($anchor['html']) ? Str::limit((string) $anchor['html'], 800, '') : null,
            // Ponto do pino dentro do artboard (px).
            'bx' => isset($anchor['bx']) ? (int) $anchor['bx'] : null,
            'by' => isset($anchor['by']) ? (int) $anchor['by'] : null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
