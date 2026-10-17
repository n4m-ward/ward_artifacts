<?php

namespace App\Http\Controllers;

use App\Artifacts\ContentGateway;
use App\Designs\DesignState;
use App\Designs\DesignView;
use App\Models\Artifact;
use Illuminate\Http\Response;

/**
 * /d/{uuid}/snapshot (URL assinada de poucos minutos): o canvas inteiro em
 * modo estático, sem barra nem controles e sem ao vivo, para o Chrome do
 * servidor tirar o print que a IA confere (get_design_image).
 */
class DesignSnapshotController extends Controller
{
    public function __invoke(string $artifact, DesignState $state, ContentGateway $gateway): Response
    {
        $design = Artifact::where('uuid', $artifact)->first();

        abort_unless($design?->isDesign(), 404);

        return response()->view('designs.snapshot', [
            'artifact' => $design,
            'config' => [
                'design' => $design->uuid,
                'state' => $state->toArray($design, 10),
                'static' => true,
                'contentOrigin' => $gateway->origin(),
                'sandbox' => $gateway->sandbox(),
                'allow' => DesignView::ALLOW,
            ],
        ])->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
    }
}
