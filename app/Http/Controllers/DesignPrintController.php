<?php

namespace App\Http\Controllers;

use App\Artifacts\ContentGateway;
use App\Designs\DesignView;
use App\Models\Artifact;
use App\Models\DesignBoard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * /d/{uuid}/print?boards=1,2: vários artboards, um por página do PDF, cada
 * página do tamanho do artboard. A página imprime sozinha quando tudo carrega.
 */
class DesignPrintController extends Controller
{
    public function __invoke(Request $request, string $artifact, ContentGateway $gateway): Response
    {
        $design = Artifact::where('uuid', $artifact)->first();
        abort_unless($design?->isDesign(), 404);
        Gate::authorize('view', $design);

        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('boards', ''))));
        $boards = $design->orderedBoards()->get()
            ->when($ids !== [], fn ($boards) => $boards->whereIn('id', $ids))
            ->values();

        abort_if($boards->isEmpty(), 404);

        return response()->view('designs.print', [
            'artifact' => $design,
            'boards' => $boards->map(fn (DesignBoard $board) => [
                'id' => $board->id,
                'title' => $board->title ?: $board->name,
                'w' => $board->w,
                'h' => $board->h,
                'url' => $gateway->boardUrl($board, 30),
            ]),
            'sandbox' => $gateway->sandbox(),
            'allow' => DesignView::ALLOW,
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
