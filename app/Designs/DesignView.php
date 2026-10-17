<?php

namespace App\Designs;

use App\Artifacts\ContentGateway;
use App\Models\Artifact;
use App\Models\DesignBoard;
use App\Models\DesignNote;
use Illuminate\Http\Response;

/**
 * Monta a página do canvas: posições normalizadas (o canto superior esquerdo
 * vira 0,0 com uma margem), tamanho total e URLs assinadas de cada artboard.
 */
class DesignView
{
    private const PADDING = 120;

    private const NOTE_COLORS = [
        'gray' => '#E4E4E7', 'yellow' => '#FEF3C7', 'blue' => '#DBEAFE',
        'green' => '#DCFCE7', 'pink' => '#FCE7F3', 'purple' => '#EDE9FE',
    ];

    public function __construct(private ContentGateway $gateway) {}

    public function render(Artifact $design, bool $preview = false): Response
    {
        $boards = $design->orderedBoards()->get();
        $notes = $design->notes()->orderBy('id')->get();

        $xs = $boards->map(fn (DesignBoard $b) => [$b->x, $b->x + $b->w])->flatten()
            ->merge($notes->map(fn (DesignNote $n) => [$n->x, $n->x + ($n->w ?? 260)])->flatten());
        $ys = $boards->map(fn (DesignBoard $b) => [$b->y, $b->y + $b->h])->flatten()
            ->merge($notes->map(fn (DesignNote $n) => [$n->y, $n->y + 120])->flatten());

        $minX = (int) ($xs->min() ?? 0);
        $minY = (int) ($ys->min() ?? 0);
        $ttl = $preview ? (int) config('ward.artifacts.preview_content_ttl_minutes') : null;

        return response()->view('artifacts.design', [
            'artifact' => $design,
            'sandbox' => $this->gateway->sandbox(),
            'preview' => $preview,
            'boards' => $boards->map(fn (DesignBoard $b) => [
                'name' => $b->name,
                'title' => $b->title,
                'w' => $b->w,
                'h' => $b->h,
                'interactive' => $b->interactive,
                'left' => $b->x - $minX + self::PADDING,
                'top' => $b->y - $minY + self::PADDING,
                'url' => $this->gateway->boardUrl($b, $ttl),
            ])->all(),
            'notes' => $notes->map(fn (DesignNote $n) => [
                'kind' => $n->kind,
                'text' => $n->text,
                'w' => $n->w,
                'left' => $n->x - $minX + self::PADDING,
                'top' => $n->y - $minY + self::PADDING,
                'background' => self::NOTE_COLORS[$n->color ?? 'yellow'] ?? self::NOTE_COLORS['yellow'],
            ])->all(),
            'bounds' => [
                'w' => (int) ($xs->max() ?? 0) - $minX + self::PADDING * 2,
                'h' => (int) ($ys->max() ?? 0) - $minY + self::PADDING * 2,
            ],
        ]);
    }
}
