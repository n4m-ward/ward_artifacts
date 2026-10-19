<?php

namespace App\Http\Controllers;

use App\Actions\Designs\DesignCommentImages;
use App\Designs\DesignStorage;
use App\Designs\InvalidDesign;
use App\Models\Artifact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Imagens dos comentários do canvas: enviar antes de publicar (quem pode ver o design
 * comenta), tirar uma enviada, e servir por link assinado (também no MCP App, sem sessão).
 */
class DesignCommentImageController extends Controller
{
    public function __construct(private DesignCommentImages $images) {}

    public function store(Request $request, string $artifact): JsonResponse
    {
        $design = $this->design($artifact);
        Gate::authorize('view', $design);

        $request->validate(['file' => ['required', 'file', 'max:'.(DesignCommentImages::MAX_BYTES / 1024)]]);

        try {
            $image = $this->images->upload($design, $request->user(), (string) file_get_contents($request->file('file')->getRealPath()));
        } catch (InvalidDesign $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['image' => $image->toCanvas($design)]);
    }

    public function destroy(Request $request, string $artifact, int $image): JsonResponse
    {
        $design = $this->design($artifact);
        Gate::authorize('view', $design);

        $this->images->discard($design, $request->user(), $image);

        return response()->json(['ok' => true]);
    }

    public function show(Request $request, string $artifact, string $name, DesignStorage $storage): Response
    {
        abort_unless($request->hasValidSignature(), 403);

        $design = $this->design($artifact);
        $image = $design->commentImages()->where('name', $name)->firstOrFail();
        $path = $image->storagePath($design);
        abort_unless($storage->disk()->fileExists($path), 404);

        return $storage->disk()->response($path, null, [
            'Content-Type' => $image->mime,
            'Cache-Control' => 'private, max-age=43200',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }

    private function design(string $uuid): Artifact
    {
        $design = Artifact::where('uuid', $uuid)->first();
        abort_unless($design?->isDesign(), 404);

        return $design;
    }
}
