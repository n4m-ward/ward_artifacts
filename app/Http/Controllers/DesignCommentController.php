<?php

namespace App\Http\Controllers;

use App\Actions\Designs\DesignCommentImages;
use App\Actions\Designs\DesignComments;
use App\Designs\DesignState;
use App\Designs\InvalidDesign;
use App\Models\Artifact;
use App\Models\DesignComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Comentários do canvas web e o relato do que a pessoa está vendo (que a IA lê
 * no get_design). Comentar exige poder ver o design; resolver e apagar, ser o
 * autor ou poder editar o design.
 */
class DesignCommentController extends Controller
{
    public function __construct(private DesignComments $comments, private DesignState $state) {}

    public function store(Request $request, string $artifact): JsonResponse
    {
        $design = $this->design($artifact);
        Gate::authorize('view', $design);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.DesignComments::MAX_BODY],
            'parent' => ['nullable', 'integer'],
            'board' => ['nullable', 'integer'],
            'anchor' => ['nullable', 'array'],
            'x' => ['nullable', 'numeric'],
            'y' => ['nullable', 'numeric'],
            // Imagens já enviadas (DesignCommentImageController::store) para anexar.
            'images' => ['nullable', 'array', 'max:'.DesignCommentImages::MAX_PER_MESSAGE],
            'images.*' => ['integer'],
        ]);

        try {
            if (isset($data['parent'])) {
                $thread = $design->comments()->whereNull('parent_id')->findOrFail($data['parent']);
                $this->comments->reply($thread, $request->user(), (string) ($data['body'] ?? ''), 'web', $data['images'] ?? []);
            } else {
                $thread = $this->comments->open($design, $request->user(), [
                    'body' => (string) ($data['body'] ?? ''),
                    'images' => $data['images'] ?? [],
                    'board' => $data['board'] ?? null,
                    'anchor' => $data['anchor'] ?? null,
                    'x' => isset($data['x']) ? (int) round($data['x']) : null,
                    'y' => isset($data['y']) ? (int) round($data['y']) : null,
                ]);
            }
        } catch (InvalidDesign $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->respond($design, ['thread' => $thread->id]);
    }

    public function update(Request $request, string $artifact, int $comment): JsonResponse
    {
        $design = $this->design($artifact);
        $thread = $design->comments()->whereNull('parent_id')->findOrFail($comment);
        $this->authorizeThread($request, $design, $thread);

        $data = $request->validate(['resolved' => ['required', 'boolean']]);
        $this->comments->resolve($thread, $request->user(), $data['resolved']);

        return $this->respond($design);
    }

    public function destroy(Request $request, string $artifact, int $comment): JsonResponse
    {
        $design = $this->design($artifact);
        $model = $design->comments()->findOrFail($comment);
        $this->authorizeThread($request, $design, $model);

        $this->comments->remove($model);

        return $this->respond($design);
    }

    /**
     * O canvas avisa o que a pessoa está vendo (seleção, artboards visíveis, erros).
     */
    public function view(Request $request, string $artifact): JsonResponse
    {
        $design = $this->design($artifact);
        Gate::authorize('view', $design);

        $data = $request->validate([
            'board' => ['nullable', 'string', 'max:60'],
            'element' => ['nullable', 'array'],
            'visible' => ['nullable', 'array', 'max:50'],
            'errors' => ['nullable', 'array', 'max:20'],
        ]);

        $this->state->reportContext($design, $request->user(), $data);

        return response()->json(['ok' => true]);
    }

    private function authorizeThread(Request $request, Artifact $design, DesignComment $comment): void
    {
        abort_unless(
            $comment->user_id === $request->user()->id || $request->user()->can('update', $design),
            403,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function respond(Artifact $design, array $extra = []): JsonResponse
    {
        return response()->json($extra + ['state' => $this->state->toArray($design->fresh(), comments: true)]);
    }

    private function design(string $uuid): Artifact
    {
        $design = Artifact::where('uuid', $uuid)->first();
        abort_unless($design?->isDesign(), 404);

        return $design;
    }
}
