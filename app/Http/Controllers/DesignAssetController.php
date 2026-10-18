<?php

namespace App\Http\Controllers;

use App\Actions\Designs\AddDesignAsset;
use App\Designs\DesignStorage;
use App\Designs\InvalidDesign;
use App\Models\Artifact;
use App\Models\DesignAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Biblioteca de imagens de um design (canvas web): listar, enviar, apagar e
 * a miniatura para o painel. Nos artboards a imagem é "assets/{nome}".
 */
class DesignAssetController extends Controller
{
    public function __construct(private AddDesignAsset $assets, private DesignStorage $storage) {}

    public function index(string $artifact): JsonResponse
    {
        $design = $this->design($artifact);
        Gate::authorize('view', $design);

        return $this->list($design);
    }

    public function store(Request $request, string $artifact): JsonResponse
    {
        $design = $this->design($artifact);
        Gate::authorize('update', $design);

        $request->validate(['file' => ['required', 'file', 'max:'.(AddDesignAsset::MAX_BYTES / 1024)]]);
        $file = $request->file('file');

        try {
            $asset = $this->assets->handle($design, $request->user(), (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
        } catch (InvalidDesign $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->list($design->fresh(), ['asset' => $asset->toCanvas()]);
    }

    public function destroy(string $artifact, string $name): JsonResponse
    {
        $design = $this->design($artifact);
        Gate::authorize('update', $design);

        $this->assets->remove($design, $design->assets()->where('name', $name)->firstOrFail());

        return $this->list($design->fresh());
    }

    public function show(string $artifact, string $name): Response
    {
        $design = $this->design($artifact);
        Gate::authorize('view', $design);

        $asset = $design->assets()->where('name', $name)->firstOrFail();

        return $this->storage->disk()->response($this->storage->assetPath($design, $asset->name), null, [
            'Content-Type' => $asset->mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            // SVG enviado não roda script nesta origem (a do app).
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function list(Artifact $design, array $extra = []): JsonResponse
    {
        return response()->json($extra + [
            'assets' => $design->assets()->orderByDesc('id')->get()->map(fn (DesignAsset $asset) => $asset->toCanvas() + [
                'url' => route('designs.assets.show', [$design->uuid, $asset->name]),
            ])->values(),
        ]);
    }

    private function design(string $uuid): Artifact
    {
        $design = Artifact::where('uuid', $uuid)->first();
        abort_unless($design?->isDesign(), 404);

        return $design;
    }
}
