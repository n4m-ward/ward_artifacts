<?php

namespace App\Whiteboards;

use App\Models\Artifact;
use App\Models\DesignComment;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Comentários num quadro: mesma tabela dos comentários do design (design_comments),
 * com a âncora num elemento ({"element": id}) ou num ponto (x/y) do quadro.
 */
class WhiteboardComments
{
    public const MAX_BODY = 4000;

    public function __construct(private WhiteboardStore $store) {}

    /**
     * @param  array{body: string, element?: ?string, x?: ?float, y?: ?float}  $data
     */
    public function open(Artifact $board, User $user, array $data, string $source = 'web'): DesignComment
    {
        $element = isset($data['element']) && $data['element'] !== '' ? (string) $data['element'] : null;

        if ($element !== null && $this->store->find($board, $element) === null) {
            throw new InvalidWhiteboard('Este elemento não existe mais.');
        }

        if ($element === null && (! isset($data['x']) || ! isset($data['y']))) {
            throw new InvalidWhiteboard('Um comentário precisa de um elemento ou de um ponto do quadro.');
        }

        $comment = $board->comments()->create([
            'number' => (int) $board->comments()->max('number') + 1,
            'user_id' => $user->id,
            'anchor' => $element !== null ? ['element' => $element] : null,
            'x' => isset($data['x']) ? (int) round((float) $data['x']) : null,
            'y' => isset($data['y']) ? (int) round((float) $data['y']) : null,
            'body' => $this->body($data['body'] ?? ''),
            'source' => $source,
        ]);

        $this->store->touch($board);

        return $comment;
    }

    public function reply(DesignComment $thread, User $user, string $body, string $source = 'web'): DesignComment
    {
        $reply = $thread->artifact->comments()->create([
            'parent_id' => $thread->id,
            'user_id' => $user->id,
            'body' => $this->body($body),
            'source' => $source,
        ]);

        $this->store->touch($thread->artifact);

        return $reply;
    }

    public function resolve(DesignComment $thread, User $user, bool $resolved = true): void
    {
        $thread->forceFill(['resolved_at' => $resolved ? now() : null, 'resolved_by' => $resolved ? $user->id : null])->save();
        $this->store->touch($thread->artifact);
    }

    public function remove(DesignComment $comment): void
    {
        $board = $comment->artifact;
        $comment->delete();
        $this->store->touch($board);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function threads(Artifact $board, bool $openOnly = false): array
    {
        return $board->comments()->whereNull('parent_id')
            ->when($openOnly, fn ($query) => $query->whereNull('resolved_at'))
            ->with(['user', 'resolver', 'images', 'replies.user', 'replies.images', 'artifact'])
            ->orderByDesc('number')->limit(200)->get()
            ->map(fn (DesignComment $comment) => [
                'element' => $comment->anchor['element'] ?? null,
            ] + $comment->toThread())->values()->all();
    }

    private function body(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            throw new InvalidWhiteboard('Escreva o comentário.');
        }

        return Str::limit($body, self::MAX_BODY, '');
    }
}
