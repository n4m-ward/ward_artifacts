<?php

namespace App\Http\Controllers;

use App\Actions\Designs\DeleteArtboard;
use App\Actions\Designs\DesignMutation;
use App\Actions\Designs\UpdateDesignLayout;
use App\Actions\Designs\UpsertArtboard;
use App\Designs\DesignState;
use App\Designs\DesignStorage;
use App\Designs\InvalidDesign;
use App\Models\Artifact;
use App\Models\DesignBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Edição à mão no canvas web: a página manda um lote de operações e recebe o
 * estado novo. Usa as mesmas ações das ferramentas MCP, então a pessoa e a IA
 * mexem no mesmo canvas.
 *
 * Operações:
 * - board.update {id, x?, y?, w?, h?, title?, name?}
 * - board.duplicate {id, x?, y?}
 * - board.create {name, kind, x, y, w, h, html, title?, interactive?} (desfazer um apagar)
 * - board.delete {id}
 * - note.upsert {key, kind?, x?, y?, w?, text?, color?, size?, bold?}
 * - note.delete {key}
 * - board.html {id, html, base?} (editor visual; base = revisão em que a edição foi feita)
 */
class DesignEditController extends Controller
{
    public function __construct(
        private UpdateDesignLayout $layout,
        private UpsertArtboard $upsert,
        private DeleteArtboard $delete,
        private DesignStorage $storage,
        private DesignState $state,
    ) {}

    public function ops(Request $request, string $artifact): JsonResponse
    {
        $design = $this->design($artifact);
        Gate::authorize('update', $design);

        $request->validate([
            'ops' => ['required', 'array', 'min:1', 'max:200'],
            'ops.*' => ['array'],
            'ops.*.op' => ['required', 'string', 'in:board.update,board.duplicate,board.create,board.delete,board.html,note.upsert,note.delete'],
        ]);

        // validate() devolveria só "op": cada operação leva os próprios campos.
        $ops = $request->input('ops');

        $results = [];

        try {
            foreach ($ops as $index => $op) {
                $results[$index] = $this->apply($design, $request, $op);
                $design->refresh();
            }
        } catch (InvalidDesign $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'state' => $this->state->toArray($design->fresh(), comments: true),
            ], 422);
        }

        return response()->json([
            'results' => $results,
            'state' => $this->state->toArray($design->fresh(), comments: true),
        ]);
    }

    /**
     * HTML de um artboard (para o editor visual e para desfazer).
     */
    public function source(string $artifact, int $board): JsonResponse
    {
        $design = $this->design($artifact);
        Gate::authorize('update', $design);

        $model = $design->boards()->findOrFail($board);

        return response()->json(['revision' => $model->revision, 'html' => $this->storage->html($model)])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function apply(Artifact $design, Request $request, array $op): array
    {
        return match ($op['op']) {
            'board.update' => $this->updateBoard($design, $op),
            'board.duplicate' => $this->duplicateBoard($design, $request, $op),
            'board.create' => $this->createBoard($design, $request, $op),
            'board.delete' => $this->deleteBoard($design, $op),
            'board.html' => $this->saveHtml($design, $request, $op),
            'note.upsert' => $this->upsertNote($design, $op),
            'note.delete' => $this->deleteNote($design, $op),
        };
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function updateBoard(Artifact $design, array $op): array
    {
        $board = $this->board($design, $op);

        $change = array_filter([
            'name' => $board->name,
            'x' => $this->int($op, 'x'),
            'y' => $this->int($op, 'y'),
            'w' => $this->int($op, 'w'),
            'h' => $this->int($op, 'h'),
            'title' => array_key_exists('title', $op) ? (string) $op['title'] : null,
            'new_name' => isset($op['name']) && $op['name'] !== $board->name ? trim((string) $op['name']) : null,
        ], fn ($value) => $value !== null);

        $this->layout->handle($design, [$change]);

        return ['id' => $board->id];
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function duplicateBoard(Artifact $design, Request $request, array $op): array
    {
        $source = $this->board($design, $op);

        $copy = $this->upsert->handle($design, $request->user(), [
            'name' => $this->freeName($design, $source->name),
            'title' => $source->title ? Str::limit($source->title.' (cópia)', 120, '') : null,
            'kind' => $source->kind->value,
            'html' => $this->storage->html($source),
            'x' => $this->int($op, 'x') ?? $source->x + $source->w + (int) config('ward.designs.gap'),
            'y' => $this->int($op, 'y') ?? $source->y,
            'w' => $source->w,
            'h' => $source->h,
            'interactive' => $source->interactive,
        ]);

        return ['id' => $copy->id, 'name' => $copy->name];
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function createBoard(Artifact $design, Request $request, array $op): array
    {
        $name = trim((string) ($op['name'] ?? ''));

        if ($design->boards()->where('name', $name)->exists()) {
            $name = $this->freeName($design, $name);
        }

        $board = $this->upsert->handle($design, $request->user(), [
            'name' => $name,
            'title' => $op['title'] ?? null,
            'kind' => $op['kind'] ?? 'fixed',
            'html' => (string) ($op['html'] ?? ''),
            'x' => $this->int($op, 'x'),
            'y' => $this->int($op, 'y'),
            'w' => $this->int($op, 'w'),
            'h' => $this->int($op, 'h'),
            'interactive' => (bool) ($op['interactive'] ?? false),
        ]);

        return ['id' => $board->id, 'name' => $board->name];
    }

    /**
     * HTML novo de um artboard, vindo do editor visual. Se a IA (ou outra aba)
     * mudou o artboard depois que a edição começou, recusa em vez de sobrescrever.
     *
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function saveHtml(Artifact $design, Request $request, array $op): array
    {
        $board = $this->board($design, $op);

        if (isset($op['base']) && (int) $op['base'] !== $board->revision) {
            throw new InvalidDesign("O artboard \"{$board->name}\" mudou enquanto você editava. O canvas foi atualizado: refaça a última mudança.");
        }

        $saved = $this->upsert->handle($design, $request->user(), [
            'name' => $board->name,
            'html' => (string) ($op['html'] ?? ''),
        ]);

        return ['id' => $saved->id, 'revision' => $saved->revision];
    }

    /**
     * Devolve o artboard apagado inteiro, para a página poder desfazer.
     *
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function deleteBoard(Artifact $design, array $op): array
    {
        $board = $this->board($design, $op);
        $removed = [
            'name' => $board->name,
            'title' => $board->title,
            'kind' => $board->kind->value,
            'x' => $board->x,
            'y' => $board->y,
            'w' => $board->w,
            'h' => $board->h,
            'interactive' => $board->interactive,
            'html' => $this->storage->html($board),
        ];

        $this->delete->handle($design, $board->name);

        return ['removed' => $removed];
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function upsertNote(Artifact $design, array $op): array
    {
        $note = array_filter([
            'key' => (string) ($op['key'] ?? ''),
            'kind' => $op['kind'] ?? null,
            'x' => $this->int($op, 'x'),
            'y' => $this->int($op, 'y'),
            'w' => $this->int($op, 'w'),
            'text' => isset($op['text']) ? Str::limit((string) $op['text'], 5000, '') : null,
            'color' => $op['color'] ?? null,
            'size' => $op['size'] ?? null,
            'bold' => isset($op['bold']) ? (bool) $op['bold'] : null,
        ], fn ($value) => $value !== null);

        $this->layout->handle($design, [], [$note]);

        return ['key' => $note['key']];
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function deleteNote(Artifact $design, array $op): array
    {
        $note = $design->notes()->where('key', (string) ($op['key'] ?? ''))->first()
            ?? throw new InvalidDesign('Nota não encontrada.');

        $removed = $note->toCanvas();
        $this->layout->handle($design, [], [['key' => $note->key, 'delete' => true]]);

        return ['removed' => $removed];
    }

    private function design(string $uuid): Artifact
    {
        $design = Artifact::where('uuid', $uuid)->first();
        abort_unless($design?->isDesign(), 404);

        return $design;
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function board(Artifact $design, array $op): DesignBoard
    {
        return $design->boards()->find((int) ($op['id'] ?? 0))
            ?? throw new InvalidDesign('Este artboard não existe mais. O canvas foi atualizado.');
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function int(array $op, string $key): ?int
    {
        return isset($op[$key]) && is_numeric($op[$key]) ? (int) round((float) $op[$key]) : null;
    }

    /**
     * "Home" → "Home 2", "Home 3"... (nomes de artboard são únicos no design).
     */
    private function freeName(Artifact $design, string $name): string
    {
        $base = preg_replace('/ \d+$/', '', $name) ?: 'Artboard';
        $taken = $design->boards()->pluck('name')->all();

        for ($n = 2; ; $n++) {
            $candidate = Str::limit($base, 55, '').' '.$n;

            if (! in_array($candidate, $taken, true) && DesignMutation::validName($candidate)) {
                return $candidate;
            }
        }
    }
}
