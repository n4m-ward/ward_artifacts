<?php

namespace App\Http\Controllers;

use App\Designs\DesignSystems;
use App\Models\Artifact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Apoio do canvas aos design systems: busca (painel Componentes do editor) e
 * a documentação renderizada (painel Documentação da página do design system).
 */
class DesignSystemController extends Controller
{
    public function search(Request $request, DesignSystems $systems): JsonResponse
    {
        $current = $systems->find((string) $request->query('prefer'));

        return response()->json([
            'design_systems' => $systems->search($request->query('q'))
                ->sortByDesc(fn (Artifact $system) => $current && $system->id === $current->id)
                ->map(fn (Artifact $system) => $systems->summary($system) + [
                    'live_url' => route('designs.live', $system->uuid),
                ])->values(),
        ]);
    }

    public function docs(string $artifact): JsonResponse
    {
        $system = Artifact::where('uuid', $artifact)->where('design_system', true)->firstOrFail();
        Gate::authorize('view', $system);

        return response()->json([
            'title' => $system->title,
            'description' => $system->description,
            'docs' => $system->docs,
            'html' => self::markdown($system->docs),
        ]);
    }

    /**
     * Markdown da documentação, sem HTML cru nem links perigosos.
     */
    public static function markdown(?string $docs): string
    {
        return $docs ? (string) Str::markdown($docs, ['html_input' => 'escape', 'allow_unsafe_links' => false]) : '';
    }
}
