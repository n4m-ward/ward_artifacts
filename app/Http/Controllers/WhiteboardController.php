<?php

namespace App\Http\Controllers;

use App\Artifacts\ContentGateway;
use App\Models\Artifact;
use App\Whiteboards\InvalidWhiteboard;
use App\Whiteboards\WhiteboardComments;
use App\Whiteboards\WhiteboardEditor;
use App\Whiteboards\WhiteboardFiles;
use App\Whiteboards\WhiteboardPresence;
use App\Whiteboards\WhiteboardSession;
use App\Whiteboards\WhiteboardStore;
use App\Whiteboards\WhiteboardTemplates;
use App\Whiteboards\WhiteboardVersions;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tudo que o quadro aberto no navegador grava: elementos, imagens, presença,
 * comentários, votos, cronômetro, versões e templates. Mexer no conteúdo exige
 * a permissão "edit" (dono, admin ou convidado editor); comentar, votar e
 * aparecer no quadro, só poder ver.
 */
class WhiteboardController extends Controller
{
    public function elements(Request $request, string $artifact, WhiteboardStore $store): JsonResponse
    {
        $board = $this->board($artifact, 'edit');

        if (strlen((string) $request->getContent()) > (int) config('ward.whiteboards.max_batch_bytes')) {
            return response()->json(['message' => 'Envio grande demais. Mande os elementos em partes.'], 413);
        }

        $request->validate(['elements' => ['required', 'array', 'max:2000'], 'elements.*' => ['array']]);

        return $this->attempt(fn () => $store->save($board, $request->user(), $request->input('elements')));
    }

    public function storeFile(Request $request, string $artifact, WhiteboardFiles $files, WhiteboardStore $store): JsonResponse
    {
        $board = $this->board($artifact, 'edit');
        $request->validate(['id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'], 'dataURL' => ['required', 'string']]);

        return $this->attempt(function () use ($board, $request, $files, $store) {
            $file = $files->storeDataUrl($board, $request->user(), $request->input('dataURL'), $request->input('id'));
            $store->touch($board);

            return ['id' => $file['id']];
        });
    }

    public function showFile(Request $request, string $artifact, string $file, WhiteboardFiles $files): Response
    {
        $board = Artifact::where('uuid', $artifact)->first();
        abort_unless($board?->isWhiteboard(), 404);

        if (! $request->hasValidSignature()) {
            abort_unless($request->user()?->can('view', $board), 403);
        }

        $model = $board->whiteboardFiles()->where('file_id', $file)->firstOrFail();

        return $files->disk()->response($model->storage_path, null, [
            'Content-Type' => $model->mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Access-Control-Allow-Origin' => '*',
            // SVG enviado não roda script nesta origem (a do app).
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function presence(Request $request, string $artifact, WhiteboardPresence $presence): Response
    {
        $board = $this->board($artifact, 'view');

        if ($request->boolean('leave')) {
            $presence->leave($board, $request->user());
        } else {
            $presence->report($board, $request->user(), $request->all());
        }

        return response()->noContent();
    }

    public function storeComment(Request $request, string $artifact, WhiteboardComments $comments): JsonResponse
    {
        $board = $this->board($artifact, 'view');
        $request->validate([
            'body' => ['required', 'string', 'max:'.WhiteboardComments::MAX_BODY],
            'parent' => ['nullable', 'integer'],
            'element' => ['nullable', 'string', 'max:64'],
            'x' => ['nullable', 'numeric'],
            'y' => ['nullable', 'numeric'],
        ]);

        return $this->attempt(function () use ($board, $request, $comments) {
            if ($request->filled('parent')) {
                $thread = $board->comments()->whereNull('parent_id')->findOrFail($request->integer('parent'));
                $comments->reply($thread, $request->user(), $request->input('body'));
            } else {
                $comments->open($board, $request->user(), $request->only('body', 'element', 'x', 'y'));
            }

            return ['comments' => $comments->threads($board)];
        });
    }

    public function updateComment(Request $request, string $artifact, int $comment, WhiteboardComments $comments): JsonResponse
    {
        $board = $this->board($artifact, 'view');
        $thread = $board->comments()->whereNull('parent_id')->findOrFail($comment);
        $request->validate(['resolved' => ['required', 'boolean']]);

        $comments->resolve($thread, $request->user(), $request->boolean('resolved'));

        return response()->json(['comments' => $comments->threads($board)]);
    }

    public function destroyComment(Request $request, string $artifact, int $comment, WhiteboardComments $comments): JsonResponse
    {
        $board = $this->board($artifact, 'view');
        $model = $board->comments()->findOrFail($comment);

        // Quem escreveu, ou quem pode editar o quadro.
        abort_unless($model->user_id === $request->user()->id || $request->user()->can('edit', $board), 403);

        $comments->remove($model);

        return response()->json(['comments' => $comments->threads($board)]);
    }

    public function vote(Request $request, string $artifact, WhiteboardSession $session): JsonResponse
    {
        $board = $this->board($artifact, 'view');
        $request->validate(['element' => ['required', 'string', 'max:64'], 'delta' => ['required', 'integer', 'in:1,-1']]);

        return $this->attempt(function () use ($board, $request, $session) {
            $session->vote($board, $request->user(), $request->input('element'), $request->integer('delta'));

            return ['session' => $session->state($board->fresh(), $request->user())];
        });
    }

    public function voting(Request $request, string $artifact, WhiteboardSession $session): JsonResponse
    {
        $board = $this->board($artifact, 'edit');
        $request->validate(['action' => ['required', 'in:start,close,clear'], 'max' => ['nullable', 'integer', 'min:1', 'max:20']]);

        match ($request->input('action')) {
            'start' => $session->startVoting($board, $request->user(), $request->integer('max', 3)),
            'close' => $session->closeVoting($board),
            'clear' => $session->clearVoting($board),
        };

        return response()->json(['session' => $session->state($board->fresh(), $request->user())]);
    }

    public function timer(Request $request, string $artifact, WhiteboardSession $session): JsonResponse
    {
        $board = $this->board($artifact, 'edit');
        $request->validate(['action' => ['required', 'in:start,pause,resume,stop'], 'seconds' => ['nullable', 'integer', 'min:10', 'max:7200']]);

        $session->timer($board, $request->user(), $request->input('action'), $request->integer('seconds', 300));

        return response()->json(['session' => $session->state($board->fresh(), $request->user())]);
    }

    public function versions(string $artifact, WhiteboardVersions $versions): JsonResponse
    {
        $board = $this->board($artifact, 'view');

        return response()->json(['versions' => $versions->list($board)]);
    }

    public function saveVersion(Request $request, string $artifact, WhiteboardVersions $versions): JsonResponse
    {
        $board = $this->board($artifact, 'edit');
        $request->validate(['label' => ['nullable', 'string', 'max:120']]);

        return $this->attempt(function () use ($board, $request, $versions) {
            $versions->save($board, $request->user(), $request->input('label'));

            return ['versions' => $versions->list($board)];
        });
    }

    public function restoreVersion(Request $request, string $artifact, int $number, WhiteboardVersions $versions): JsonResponse
    {
        $board = $this->board($artifact, 'edit');

        return $this->attempt(fn () => ['seq' => $versions->restore($board, $request->user(), $number)]);
    }

    public function template(Request $request, string $artifact, WhiteboardEditor $editor, WhiteboardTemplates $templates): JsonResponse
    {
        $board = $this->board($artifact, 'edit');
        $request->validate(['name' => ['required', 'string', 'in:'.implode(',', array_keys(WhiteboardTemplates::NAMES))], 'x' => ['nullable', 'numeric'], 'y' => ['nullable', 'numeric']]);

        return $this->attempt(function () use ($board, $request, $editor, $templates) {
            // Sem x/y: num lugar livre, à direita do que já existe (não cobre o conteúdo).
            [$x, $y] = $request->filled(['x', 'y']) ? [(float) $request->input('x'), (float) $request->input('y')] : $editor->freeSpot($editor->existing($board));
            $result = $editor->add($board, $request->user(), $templates->items($request->input('name')), ['at' => ['x' => $x, 'y' => $y]]);

            return ['seq' => $result['seq'], 'ids' => $result['ids']];
        });
    }

    /**
     * Designs que a pessoa vê e os artboards deles, para pôr um artboard no quadro.
     */
    public function artboards(Request $request, string $artifact): JsonResponse
    {
        $this->board($artifact, 'edit');
        $user = $request->user();
        $search = trim((string) $request->query('q', ''));

        $designs = Artifact::with(['user', 'project', 'orderedBoards'])
            ->where('type', 'design')
            ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%'))
            ->latest('updated_at')->limit(60)->get()
            ->filter(fn (Artifact $design) => $user->can('view', $design) && $design->orderedBoards->isNotEmpty())
            ->take(20)
            ->map(fn (Artifact $design) => [
                'id' => $design->uuid,
                'title' => $design->title,
                'owner' => $design->user->username,
                'project' => $design->project->name,
                'boards' => $design->orderedBoards->map(fn ($board) => [
                    'name' => $board->name,
                    'title' => $board->title,
                    'w' => $board->w,
                    'h' => $board->h,
                    'embed' => route('whiteboards.artboard', [$design->uuid, $board->id]),
                ])->values()->all(),
            ])->values()->all();

        return response()->json(['designs' => $designs]);
    }

    /**
     * Artboard embutido num quadro: o link é estável (/w/artboard/{design}/{board});
     * aqui ele vira o link assinado do host de conteúdo, que vence logo.
     */
    public function artboard(Request $request, string $design, int $board, ContentGateway $gateway): Response
    {
        $model = Artifact::where('uuid', $design)->first();
        abort_unless($model?->isDesign() && Gate::allows('view', $model), 404);

        $artboard = $model->boards()->findOrFail($board);

        return redirect()->away($gateway->boardUrl($artboard))->header('Cache-Control', 'no-store, private');
    }

    private function board(string $uuid, string $ability): Artifact
    {
        $board = Artifact::where('uuid', $uuid)->first();
        abort_unless($board?->isWhiteboard(), 404);
        Gate::authorize($ability, $board);

        return $board;
    }

    private function attempt(Closure $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (InvalidWhiteboard $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
