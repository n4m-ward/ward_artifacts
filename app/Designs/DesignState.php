<?php

namespace App\Designs;

use App\Artifacts\ContentGateway;
use App\Models\Artifact;
use App\Models\DesignBoard;
use App\Models\DesignComment;
use App\Models\DesignNote;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Estado do canvas que o navegador desenha: artboards (com URL assinada do
 * conteúdo), notas e a revisão. É o mesmo JSON na carga da página e no ao vivo.
 */
class DesignState
{
    public function __construct(private ContentGateway $gateway) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(Artifact $design, ?int $contentTtlMinutes = null, bool $comments = false): array
    {
        $boards = $design->orderedBoards()->get();
        $byName = $boards->keyBy('name');
        $renderer = app(BoardRenderer::class);

        return [
            'revision' => $design->design_revision,
            'pages' => $design->pages()->orderBy('sort')->orderBy('id')->get()->map(fn ($page) => ['key' => $page->key, 'name' => $page->name])->values()->all(),
            'tokens' => $design->design_tokens,
            'boards' => $boards->map(fn (DesignBoard $board) => [
                'id' => $board->id,
                'name' => $board->name,
                'title' => $board->title,
                'kind' => $board->kind->value,
                'page' => $board->page,
                'description' => $board->description,
                'tags' => $board->tags,
                // Sem fundo próprio (componente de design system, ou artboard que só vincula um): transparente no canvas.
                'transparent' => $design->isDesignSystem() || $this->onlyLinksComponents($board),
                // Muda quando o artboard, um componente que ele usa ou os tokens mudam (o canvas recarrega).
                'version' => $version = $renderer->version($design, $board, $byName),
                'x' => $board->x,
                'y' => $board->y,
                'w' => $board->w,
                'h' => $board->h,
                'interactive' => $board->interactive,
                'revision' => $board->revision,
                'url' => $this->gateway->boardUrl($board, $contentTtlMinutes, $version),
            ])->values()->all(),
            'notes' => $design->notes()->orderBy('id')->get()->map(fn (DesignNote $note) => $note->toCanvas())->values()->all(),
            // Comentários só para quem está logado (nunca no link público).
            'comments' => $comments ? $this->comments($design) : null,
        ];
    }

    /**
     * Artboard criado por import_component (link): só inclui componentes de design system.
     */
    private function onlyLinksComponents(DesignBoard $board): bool
    {
        $includes = $board->includes ?? [];

        return $includes !== [] && $board->size_bytes < 1024
            && collect($includes)->every(fn ($reference) => str_contains($reference, ':'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function comments(Artifact $design, bool $openOnly = false): array
    {
        return $design->comments()->whereNull('parent_id')
            ->when($openOnly, fn ($query) => $query->whereNull('resolved_at'))
            ->with(['user', 'board', 'resolver', 'replies.user'])
            ->orderByDesc('number')->limit(200)->get()
            ->map(fn (DesignComment $comment) => $comment->toThread())->values()->all();
    }

    /**
     * URL do ao vivo para esta página. Assinada, para funcionar também em quem
     * abriu por link público ou pré-visualização (sem sessão).
     */
    public function liveUrl(Artifact $design, DateTimeInterface $expires): string
    {
        return URL::temporarySignedRoute('designs.live', $expires, ['artifact' => $design->uuid]);
    }

    /**
     * Tudo que o MCP App precisa para desenhar e acompanhar o canvas.
     *
     * @return array<string, mixed>
     */
    public function appConfig(Artifact $design): array
    {
        return [
            'design' => $design->uuid,
            'title' => $design->title,
            'url' => $design->url(),
            'state' => $this->toArray($design, comments: true),
            'liveUrl' => $this->liveUrl($design, now()->addMinutes((int) config('ward.artifacts.preview_session_minutes'))),
            'contentOrigin' => $this->gateway->origin(),
            'sandbox' => $this->gateway->sandbox(),
            'allow' => DesignView::ALLOW,
            // O app busca de novo se o estado for velho (chat reaberto, tokens vencidos).
            'issuedAt' => now()->getTimestampMs(),
        ];
    }

    /**
     * O MCP App avisa qual artboard a pessoa selecionou; get_design repassa à IA.
     */
    public function reportViewing(Artifact $design, User $user, ?string $board): void
    {
        $this->reportContext($design, $user, ['board' => $board]);
    }

    /**
     * O que a pessoa está vendo no canvas (página do design ou MCP App): artboard
     * e elemento selecionados, artboards visíveis e erros de script. Vem do
     * navegador, então tudo é limitado e conferido contra o design.
     *
     * @param  array<string, mixed>  $context
     */
    public function reportContext(Artifact $design, User $user, array $context): void
    {
        $key = $this->viewingKey($design, $user);
        $names = $design->boards()->pluck('name')->all();
        $known = fn ($name) => is_string($name) && in_array($name, $names, true);

        $board = $known($context['board'] ?? null) ? $context['board'] : null;
        $element = is_array($context['element'] ?? null) && $board ? array_filter([
            'path' => preg_match('#^\d{1,4}(/\d{1,4}){0,30}$#', (string) ($context['element']['path'] ?? '')) ? $context['element']['path'] : null,
            'tag' => preg_match('/^[a-z][a-z0-9-]{0,30}$/', (string) ($context['element']['tag'] ?? '')) ? $context['element']['tag'] : null,
            'text' => Str::limit((string) ($context['element']['text'] ?? ''), 120, ''),
            'html' => Str::limit((string) ($context['element']['html'] ?? ''), 800, ''),
        ], fn ($value) => $value !== null && $value !== '') : null;
        $visible = array_values(array_slice(array_filter((array) ($context['visible'] ?? []), $known), 0, 20));
        $errors = collect((array) ($context['errors'] ?? []))
            ->filter(fn ($error) => is_array($error) && $known($error['board'] ?? null))
            ->map(fn ($error) => ['board' => $error['board'], 'message' => Str::limit((string) ($error['message'] ?? ''), 300, '')])
            ->take(10)->values()->all();

        if ($board === null && $visible === [] && $errors === []) {
            Cache::forget($key);

            return;
        }

        Cache::put($key, array_filter([
            'board' => $board,
            'element' => $element ?: null,
            'visible' => $visible,
            'errors' => $errors,
            'at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== []), now()->addMinutes((int) config('ward.designs.viewing_ttl_minutes')));
    }

    /**
     * @return array{board?: string, element?: array<string, string>, visible?: list<string>, errors?: list<array{board: string, message: string}>, at: string}|null
     */
    public function viewing(Artifact $design, User $user): ?array
    {
        $viewing = Cache::get($this->viewingKey($design, $user));

        if (! $viewing) {
            return null;
        }

        // Artboards podem ter sido apagados ou renomeados depois.
        $names = $design->boards()->pluck('name')->all();

        if (isset($viewing['board']) && ! in_array($viewing['board'], $names, true)) {
            unset($viewing['board'], $viewing['element']);
        }

        $viewing['visible'] = array_values(array_intersect($viewing['visible'] ?? [], $names));
        $viewing['errors'] = array_values(array_filter($viewing['errors'] ?? [], fn ($error) => in_array($error['board'], $names, true)));
        $viewing = array_filter($viewing, fn ($value) => $value !== []);

        return isset($viewing['board']) || isset($viewing['visible']) || isset($viewing['errors']) ? $viewing : null;
    }

    private function viewingKey(Artifact $design, User $user): string
    {
        return "ward:design-viewing:{$design->id}:{$user->id}";
    }
}
