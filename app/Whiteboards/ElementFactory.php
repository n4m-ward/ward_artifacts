<?php

namespace App\Whiteboards;

use Illuminate\Support\Str;

/**
 * Gera elementos completos do Excalidraw a partir do formato simples que a IA
 * (e os templates) usam: post-it, formas com texto dentro, texto, setas presas
 * aos elementos, linhas, frames e imagens.
 *
 * Roda no servidor porque a IA desenha mesmo com o quadro fechado. A largura do
 * texto aqui é estimada; quando o quadro abre, o navegador passa os elementos
 * marcados com customData.wardPending pelo restoreElements do Excalidraw (que
 * mede o texto de verdade e repara as ligações) e grava de volta.
 */
class ElementFactory
{
    /** Nunito no Excalidraw 0.18 (FONT_FAMILY.Nunito). */
    public const FONT_NUNITO = 6;

    public const LINE_HEIGHT = 1.35;

    public const STICKY_COLORS = [
        'yellow' => '#ffec99', 'orange' => '#ffd8a8', 'pink' => '#fcc2d7', 'red' => '#ffc9c9',
        'green' => '#b2f2bb', 'teal' => '#96f2d7', 'blue' => '#a5d8ff', 'purple' => '#d0bfff', 'gray' => '#e9ecef',
    ];

    public const STROKE_COLORS = [
        'black' => '#1e1e1e', 'gray' => '#868e96', 'red' => '#e03131', 'orange' => '#f08c00', 'yellow' => '#f59f00',
        'green' => '#2f9e44', 'teal' => '#0c8599', 'blue' => '#1971c2', 'purple' => '#6741d9', 'pink' => '#c2255c',
    ];

    public const SHAPES = ['sticky', 'rectangle', 'ellipse', 'diamond'];

    public const ARROWHEADS = ['arrow', 'bar', 'dot', 'circle', 'circle_outline', 'triangle', 'triangle_outline', 'diamond', 'diamond_outline'];

    private const STICKY_SIZE = 200;

    private const ARROW_GAP = 8;

    /**
     * Converte uma lista de itens simples. $existing são os elementos já no quadro
     * (por id), para ligar setas e frames a eles. Devolve os elementos novos e os
     * existentes que mudaram (ex.: boundElements de quem ganhou uma seta).
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $existing
     * @return list<array<string, mixed>>
     */
    public function make(array $items, array $existing = []): array
    {
        $byId = $existing;
        $out = [];
        $arrows = [];
        $frames = [];

        foreach ($items as $position => $item) {
            $type = (string) ($item['type'] ?? '');
            $id = $this->id($item['id'] ?? null);

            if (isset($byId[$id]) && ! isset($existing[$id])) {
                throw new InvalidWhiteboard("Id repetido no mesmo pedido: \"{$id}\".");
            }

            if (isset($existing[$id])) {
                throw new InvalidWhiteboard("Já existe um elemento com id \"{$id}\". Use update_elements para mudá-lo.");
            }

            match (true) {
                in_array($type, self::SHAPES, true) => $this->shape($id, $type, $item, $byId, $out),
                $type === 'text' => $this->text($id, $item, $byId, $out),
                $type === 'arrow', $type === 'line' => $arrows[] = [$id, $type, $item],
                $type === 'frame' => $frames[] = [$id, $item],
                $type === 'image' => $this->image($id, $item, $byId, $out),
                default => throw new InvalidWhiteboard("Item {$position}: tipo \"{$type}\" desconhecido. Use sticky, rectangle, ellipse, diamond, text, arrow, line, frame ou image."),
            };

            if ($type === 'arrow' || $type === 'line' || $type === 'frame') {
                $byId[$id] = ['id' => $id, 'type' => $type, 'placeholder' => true];
            }
        }

        // Setas depois das formas: podem ligar elementos criados no mesmo pedido.
        foreach ($arrows as [$id, $type, $item]) {
            unset($byId[$id]);
            $this->connector($id, $type, $item, $byId, $out);
        }

        // Frames por último (acima dos filhos, como o Excalidraw faz ao criar um frame).
        foreach ($frames as [$id, $item]) {
            unset($byId[$id]);
            $this->frame($id, $item, $byId, $out);
        }

        return array_values($out);
    }

    /**
     * Elemento base com todos os campos do Excalidraw.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function base(string $id, string $type, float $x, float $y, float $w, float $h, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'type' => $type,
            'x' => round($x, 2),
            'y' => round($y, 2),
            'width' => round($w, 2),
            'height' => round($h, 2),
            'angle' => 0,
            'strokeColor' => '#1e1e1e',
            'backgroundColor' => 'transparent',
            'fillStyle' => 'solid',
            'strokeWidth' => 2,
            'strokeStyle' => 'solid',
            'roughness' => 0,
            'opacity' => 100,
            'groupIds' => [],
            'frameId' => null,
            'index' => null,
            'roundness' => null,
            'seed' => random_int(1, 2_147_483_646),
            'version' => 1,
            'versionNonce' => random_int(1, 2_147_483_646),
            'isDeleted' => false,
            'boundElements' => null,
            'updated' => now()->getTimestampMs(),
            'link' => null,
            'locked' => false,
            'customData' => ['wardPending' => true],
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, array<string, mixed>>  $out
     */
    private function shape(string $id, string $type, array $item, array &$byId, array &$out): void
    {
        $text = isset($item['text']) ? (string) $item['text'] : '';
        $fontSize = $this->fontSize($item, $type === 'sticky' ? 20 : 20);
        $sticky = $type === 'sticky';

        [$tw, $th] = $this->measure($text, $fontSize);
        $w = (float) ($item['width'] ?? ($sticky ? self::STICKY_SIZE : max(160, min(420, $tw + 48))));
        $lines = $this->wrap($text, $fontSize, $w - 24);
        [$tw, $th] = $this->measure(implode("\n", $lines), $fontSize);
        $h = (float) ($item['height'] ?? ($sticky ? max(self::STICKY_SIZE, $th + 40) : max(80, $th + 40)));

        // Losango e elipse têm menos área útil para o texto.
        if (! isset($item['width']) && in_array($type, ['diamond', 'ellipse'], true)) {
            $w *= $type === 'diamond' ? 1.5 : 1.25;
            $h *= $type === 'diamond' ? 1.5 : 1.25;
        }

        $element = $this->base($id, $sticky ? 'rectangle' : $type, $this->coord($item, 'x'), $this->coord($item, 'y'), $w, $h, [
            'strokeColor' => $sticky ? 'transparent' : $this->stroke($item['stroke'] ?? null),
            'backgroundColor' => $sticky ? $this->stickyColor($item['color'] ?? null) : $this->fill($item['fill'] ?? ($item['color'] ?? null)),
            'strokeStyle' => $this->strokeStyle($item['style'] ?? null),
            'strokeWidth' => $sticky ? 1 : 2,
            'roundness' => $type === 'rectangle' || $sticky ? ['type' => 3] : ($type === 'diamond' ? ['type' => 2] : null),
            'link' => $this->link($item['link'] ?? null),
            'customData' => array_filter(['wardPending' => true, 'wardKind' => $sticky ? 'sticky' : null]),
        ]);

        if ($text !== '') {
            $textId = $id.'-text';
            $element['boundElements'] = [['type' => 'text', 'id' => $textId]];
            $out[$id] = $element;
            $out[$textId] = $this->boundText($textId, $id, $lines, $text, $fontSize, $element, $sticky ? 'left' : 'center', $sticky ? 'top' : 'middle');
            $byId[$textId] = $out[$textId];
        } else {
            $out[$id] = $element;
        }

        $byId[$id] = $out[$id];
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $container
     * @return array<string, mixed>
     */
    public function boundText(string $id, string $containerId, array $lines, string $original, int $fontSize, array $container, string $align = 'center', string $valign = 'middle'): array
    {
        $wrapped = implode("\n", $lines);
        [$tw, $th] = $this->measure($wrapped, $fontSize);
        $pad = 12;
        $x = $align === 'left' ? $container['x'] + $pad : $container['x'] + ($container['width'] - $tw) / 2;
        $y = $valign === 'top' ? $container['y'] + $pad : $container['y'] + ($container['height'] - $th) / 2;

        return $this->base($id, 'text', $x, $y, $tw, $th, [
            'strokeColor' => '#1e1e1e',
            'fontSize' => $fontSize,
            'fontFamily' => self::FONT_NUNITO,
            'text' => $wrapped,
            'originalText' => $original,
            'textAlign' => $align,
            'verticalAlign' => $valign,
            'containerId' => $containerId,
            'autoResize' => true,
            'lineHeight' => self::LINE_HEIGHT,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, array<string, mixed>>  $out
     */
    private function text(string $id, array $item, array &$byId, array &$out): void
    {
        $text = (string) ($item['text'] ?? '');

        if ($text === '') {
            throw new InvalidWhiteboard("Texto {$id} sem \"text\".");
        }

        $fontSize = $this->fontSize($item, 20);
        [$tw, $th] = $this->measure($text, $fontSize);

        $out[$id] = $byId[$id] = $this->base($id, 'text', $this->coord($item, 'x'), $this->coord($item, 'y'), $tw, $th, [
            'strokeColor' => $this->stroke($item['color'] ?? ($item['stroke'] ?? null)),
            'fontSize' => $fontSize,
            'fontFamily' => self::FONT_NUNITO,
            'text' => $text,
            'originalText' => $text,
            'textAlign' => in_array($item['align'] ?? null, ['left', 'center', 'right'], true) ? $item['align'] : 'left',
            'verticalAlign' => 'top',
            'containerId' => null,
            'autoResize' => true,
            'lineHeight' => self::LINE_HEIGHT,
            'link' => $this->link($item['link'] ?? null),
        ]);
    }

    /**
     * Seta ou linha. Com from/to, a seta fica presa aos dois elementos e sai da borda
     * de um em direção ao centro do outro. Sem eles, usa x/y + points (ou x2/y2).
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, array<string, mixed>>  $out
     */
    private function connector(string $id, string $type, array $item, array &$byId, array &$out): void
    {
        $from = isset($item['from']) ? (string) $item['from'] : null;
        $to = isset($item['to']) ? (string) $item['to'] : null;

        foreach ([$from, $to] as $ref) {
            if ($ref !== null && (! isset($byId[$ref]) || ! empty($byId[$ref]['placeholder']))) {
                throw new InvalidWhiteboard("Seta {$id}: o elemento \"{$ref}\" não existe (crie antes ou no mesmo pedido).");
            }
        }

        $isArrow = $type === 'arrow';
        $element = $this->base($id, $type, 0, 0, 0, 0, [
            'strokeColor' => $this->stroke($item['color'] ?? ($item['stroke'] ?? null)),
            'strokeStyle' => $this->strokeStyle($item['style'] ?? null),
            'strokeWidth' => (int) max(1, min(8, (int) ($item['strokeWidth'] ?? 2))),
            'roundness' => ($item['route'] ?? null) === 'curved' ? ['type' => 2] : null,
            'points' => [[0, 0], [0, 0]],
            'lastCommittedPoint' => null,
            'startBinding' => null,
            'endBinding' => null,
            'startArrowhead' => $isArrow ? $this->arrowhead($item['startArrow'] ?? null, null) : null,
            'endArrowhead' => $isArrow ? $this->arrowhead($item['endArrow'] ?? 'arrow', 'arrow') : null,
            'elbowed' => false,
        ]);

        if ($from !== null || $to !== null) {
            $start = $from !== null ? $byId[$from] : ['x' => $this->coord($item, 'x'), 'y' => $this->coord($item, 'y'), 'width' => 0, 'height' => 0, 'type' => 'point'];
            $end = $to !== null ? $byId[$to] : ['x' => (float) ($item['x2'] ?? $start['x'] + 200), 'y' => (float) ($item['y2'] ?? $start['y']), 'width' => 0, 'height' => 0, 'type' => 'point'];
            $element = $this->route($element, $start, $end, $item['route'] ?? 'straight', isset($item['detour']) ? (float) $item['detour'] : null, $item['side'] ?? 'below');

            if ($from !== null) {
                $element['startBinding'] = ['elementId' => $from, 'focus' => 0, 'gap' => self::ARROW_GAP];
                $this->bind($from, $id, 'arrow', $byId, $out);
            }

            if ($to !== null) {
                $element['endBinding'] = ['elementId' => $to, 'focus' => 0, 'gap' => self::ARROW_GAP];
                $this->bind($to, $id, 'arrow', $byId, $out);
            }
        } else {
            $points = $item['points'] ?? [[0, 0], [(float) ($item['x2'] ?? $this->coord($item, 'x') + 200) - $this->coord($item, 'x'), (float) ($item['y2'] ?? $this->coord($item, 'y')) - $this->coord($item, 'y')]];

            if (! is_array($points) || count($points) < 2) {
                throw new InvalidWhiteboard("Linha {$id}: informe from/to, x2/y2 ou points (2 ou mais pontos relativos a x/y).");
            }

            $element['x'] = $this->coord($item, 'x');
            $element['y'] = $this->coord($item, 'y');
            $element['points'] = array_map(fn ($point) => [(float) ($point[0] ?? 0), (float) ($point[1] ?? 0)], array_values($points));
            [$element['width'], $element['height']] = $this->pointsSize($element['points']);
        }

        $out[$id] = $element;

        $label = isset($item['label']) ? trim((string) $item['label']) : '';

        if ($label !== '' && $isArrow) {
            $textId = $id.'-label';
            $fontSize = 16;
            [$tw, $th] = $this->measure($label, $fontSize);
            $last = end($element['points']);
            $mid = [$element['x'] + $last[0] / 2, $element['y'] + $last[1] / 2];
            $out[$id]['boundElements'] = [['type' => 'text', 'id' => $textId]];
            $out[$textId] = $this->base($textId, 'text', $mid[0] - $tw / 2, $mid[1] - $th / 2, $tw, $th, [
                'strokeColor' => $element['strokeColor'],
                'fontSize' => $fontSize,
                'fontFamily' => self::FONT_NUNITO,
                'text' => $label,
                'originalText' => $label,
                'textAlign' => 'center',
                'verticalAlign' => 'middle',
                'containerId' => $id,
                'autoResize' => true,
                'lineHeight' => self::LINE_HEIGHT,
            ]);
            $byId[$textId] = $out[$textId];
        }

        $byId[$id] = $out[$id];
    }

    /**
     * Recalcula os pontos de uma seta entre dois elementos (ou pontos soltos).
     *
     * @param  array<string, mixed>  $arrow
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, mixed>
     */
    public function route(array $arrow, array $from, array $to, string $route = 'straight', ?float $detour = null, string $side = 'below'): array
    {
        $fc = [$from['x'] + $from['width'] / 2, $from['y'] + $from['height'] / 2];
        $tc = [$to['x'] + $to['width'] / 2, $to['y'] + $to['height'] / 2];

        // Contorno: sai por baixo (ou pela direita), corre na linha $detour e entra pelo mesmo lado no destino.
        if ($route === 'around' && $detour !== null) {
            $below = $side !== 'right';
            $start = $below ? [$fc[0], $from['y'] + $from['height'] + self::ARROW_GAP] : [$from['x'] + $from['width'] + self::ARROW_GAP, $fc[1]];
            $end = $below ? [$tc[0], $to['y'] + $to['height'] + self::ARROW_GAP] : [$to['x'] + $to['width'] + self::ARROW_GAP, $tc[1]];
            $points = $below
                ? [[0, 0], [0, $detour - $start[1]], [$end[0] - $start[0], $detour - $start[1]], [$end[0] - $start[0], $end[1] - $start[1]]]
                : [[0, 0], [$detour - $start[0], 0], [$detour - $start[0], $end[1] - $start[1]], [$end[0] - $start[0], $end[1] - $start[1]]];
            $arrow['x'] = round($start[0], 2);
            $arrow['y'] = round($start[1], 2);
            $arrow['points'] = array_map(fn ($point) => [round($point[0], 2), round($point[1], 2)], $points);
            [$arrow['width'], $arrow['height']] = $this->pointsSize($arrow['points']);

            return $arrow;
        }

        $start = $this->edgePoint($from, $tc, self::ARROW_GAP);
        $end = $this->edgePoint($to, $fc, self::ARROW_GAP);

        $dx = $end[0] - $start[0];
        $dy = $end[1] - $start[1];

        if ($route === 'elbow' && abs($dx) > 1 && abs($dy) > 1) {
            $points = abs($dx) >= abs($dy)
                ? [[0, 0], [$dx / 2, 0], [$dx / 2, $dy], [$dx, $dy]]
                : [[0, 0], [0, $dy / 2], [$dx, $dy / 2], [$dx, $dy]];
        } elseif ($route === 'curved') {
            // Um ponto no meio, levemente deslocado: o Excalidraw arredonda (roundness tipo 2).
            $len = max(1, hypot($dx, $dy));
            $bend = min(60, $len / 6);
            $points = [[0, 0], [$dx / 2 - $dy / $len * $bend, $dy / 2 + $dx / $len * $bend], [$dx, $dy]];
        } else {
            $points = [[0, 0], [$dx, $dy]];
        }

        $arrow['x'] = round($start[0], 2);
        $arrow['y'] = round($start[1], 2);
        $arrow['points'] = array_map(fn ($point) => [round($point[0], 2), round($point[1], 2)], $points);
        [$arrow['width'], $arrow['height']] = $this->pointsSize($arrow['points']);

        return $arrow;
    }

    /**
     * Ponto da borda de um elemento na direção de $toward, afastado $gap.
     *
     * @param  array<string, mixed>  $element
     * @param  array{0: float, 1: float}  $toward
     * @return array{0: float, 1: float}
     */
    private function edgePoint(array $element, array $toward, float $gap): array
    {
        $w = (float) $element['width'];
        $h = (float) $element['height'];
        $cx = $element['x'] + $w / 2;
        $cy = $element['y'] + $h / 2;

        if ($w <= 0 || $h <= 0) {
            return [$cx, $cy];
        }

        $dx = $toward[0] - $cx;
        $dy = $toward[1] - $cy;

        if (abs($dx) < 0.001 && abs($dy) < 0.001) {
            return [$cx, $cy];
        }

        $rx = $w / 2;
        $ry = $h / 2;

        $t = match ($element['type'] ?? 'rectangle') {
            'ellipse' => 1 / sqrt(($dx * $dx) / ($rx * $rx) + ($dy * $dy) / ($ry * $ry)),
            'diamond' => 1 / (abs($dx) / $rx + abs($dy) / $ry),
            default => min(abs($dx) > 0 ? $rx / abs($dx) : INF, abs($dy) > 0 ? $ry / abs($dy) : INF),
        };

        $len = hypot($dx, $dy);

        return [$cx + $dx * $t + $dx / $len * $gap, $cy + $dy * $t + $dy / $len * $gap];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, array<string, mixed>>  $out
     */
    private function frame(string $id, array $item, array &$byId, array &$out): void
    {
        $children = array_values(array_filter(array_map('strval', (array) ($item['children'] ?? []))));
        $pad = 40;

        if (isset($item['width'], $item['height'])) {
            $box = [$this->coord($item, 'x'), $this->coord($item, 'y'), (float) $item['width'], (float) $item['height']];
        } elseif ($children !== []) {
            $bounds = $this->bounds(array_map(function ($child) use ($byId, $id) {
                if (! isset($byId[$child])) {
                    throw new InvalidWhiteboard("Frame {$id}: o elemento \"{$child}\" não existe.");
                }

                return $byId[$child];
            }, $children));
            $box = [$bounds[0] - $pad, $bounds[1] - $pad - 20, $bounds[2] - $bounds[0] + $pad * 2, $bounds[3] - $bounds[1] + $pad * 2 + 20];
        } else {
            $box = [$this->coord($item, 'x'), $this->coord($item, 'y'), 800, 600];
        }

        $out[$id] = $byId[$id] = $this->base($id, 'frame', $box[0], $box[1], $box[2], $box[3], [
            'name' => isset($item['name']) ? Str::limit((string) $item['name'], 120, '') : null,
            'strokeColor' => '#bbb',
            'roughness' => 0,
        ]);

        foreach ($children as $child) {
            $this->setFrame($child, $id, $byId, $out);
        }
    }

    /**
     * Põe um elemento (e o texto preso a ele) dentro de um frame.
     *
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, array<string, mixed>>  $out
     */
    public function setFrame(string $child, ?string $frame, array &$byId, array &$out): void
    {
        if (! isset($byId[$child])) {
            return;
        }

        $element = $out[$child] ?? $byId[$child];
        $element['frameId'] = $frame;
        $out[$child] = $byId[$child] = $element;

        foreach ($element['boundElements'] ?? [] as $bound) {
            if (($bound['type'] ?? null) === 'text' && isset($byId[$bound['id']])) {
                $text = $out[$bound['id']] ?? $byId[$bound['id']];
                $text['frameId'] = $frame;
                $out[$bound['id']] = $byId[$bound['id']] = $text;
            }
        }
    }

    /**
     * Imagem a partir de um data: URL (o arquivo é gravado à parte pelo chamador,
     * que recebe o fileId em customData.wardFile para guardar).
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, array<string, mixed>>  $out
     */
    private function image(string $id, array $item, array &$byId, array &$out): void
    {
        $fileId = (string) ($item['fileId'] ?? '');

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $fileId)) {
            throw new InvalidWhiteboard("Imagem {$id}: informe src (data:image/...;base64,...).");
        }

        $out[$id] = $byId[$id] = $this->base($id, 'image', $this->coord($item, 'x'), $this->coord($item, 'y'), (float) ($item['width'] ?? 320), (float) ($item['height'] ?? 240), [
            'strokeColor' => 'transparent',
            'fileId' => $fileId,
            'status' => 'saved',
            'scale' => [1, 1],
            'crop' => null,
            'link' => $this->link($item['link'] ?? null),
        ]);
    }

    /**
     * Acrescenta uma ligação em boundElements de um elemento (novo ou já no quadro).
     *
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, array<string, mixed>>  $out
     */
    public function bind(string $target, string $id, string $type, array &$byId, array &$out): void
    {
        $element = $out[$target] ?? $byId[$target];
        $bound = $element['boundElements'] ?? [];

        if (! collect($bound)->contains(fn ($item) => ($item['id'] ?? null) === $id)) {
            $bound[] = ['type' => $type, 'id' => $id];
        }

        $element['boundElements'] = $bound;
        $out[$target] = $byId[$target] = $element;
    }

    /**
     * Caixa que envolve vários elementos: [minX, minY, maxX, maxY].
     *
     * @param  list<array<string, mixed>>  $elements
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function bounds(array $elements): array
    {
        $box = [INF, INF, -INF, -INF];

        foreach ($elements as $element) {
            $x1 = (float) $element['x'];
            $y1 = (float) $element['y'];
            $x2 = $x1 + (float) ($element['width'] ?? 0);
            $y2 = $y1 + (float) ($element['height'] ?? 0);

            if (isset($element['points']) && is_array($element['points'])) {
                foreach ($element['points'] as $point) {
                    $x1 = min($x1, $element['x'] + $point[0]);
                    $y1 = min($y1, $element['y'] + $point[1]);
                    $x2 = max($x2, $element['x'] + $point[0]);
                    $y2 = max($y2, $element['y'] + $point[1]);
                }
            }

            $box = [min($box[0], $x1), min($box[1], $y1), max($box[2], $x2), max($box[3], $y2)];
        }

        return $box[0] === INF ? [0, 0, 0, 0] : $box;
    }

    /**
     * Largura e altura estimadas de um texto em Nunito.
     *
     * @return array{0: float, 1: float}
     */
    public function measure(string $text, int $fontSize): array
    {
        $lines = explode("\n", $text === '' ? ' ' : $text);
        $longest = max(array_map(fn ($line) => mb_strlen($line), $lines));

        return [max(10, ceil($longest * $fontSize * 0.56)), ceil(count($lines) * $fontSize * self::LINE_HEIGHT)];
    }

    /**
     * Quebra o texto para caber numa largura (estimada).
     *
     * @return list<string>
     */
    public function wrap(string $text, int $fontSize, float $width): array
    {
        $perLine = max(4, (int) floor($width / ($fontSize * 0.56)));
        $lines = [];

        foreach (explode("\n", $text) as $paragraph) {
            $line = '';

            foreach (preg_split('/\s+/u', $paragraph) as $word) {
                $candidate = $line === '' ? $word : "{$line} {$word}";

                if (mb_strlen($candidate) <= $perLine || $line === '') {
                    $line = $candidate;
                } else {
                    $lines[] = $line;
                    $line = $word;
                }
            }

            $lines[] = $line;
        }

        return $lines;
    }

    public function stickyColor(mixed $color): string
    {
        if (is_string($color) && isset(self::STICKY_COLORS[strtolower($color)])) {
            return self::STICKY_COLORS[strtolower($color)];
        }

        return $this->hex($color) ?? self::STICKY_COLORS['yellow'];
    }

    public function fill(mixed $color): string
    {
        if (is_string($color) && isset(self::STICKY_COLORS[strtolower($color)])) {
            return self::STICKY_COLORS[strtolower($color)];
        }

        return $this->hex($color) ?? 'transparent';
    }

    public function stroke(mixed $color): string
    {
        if (is_string($color) && isset(self::STROKE_COLORS[strtolower($color)])) {
            return self::STROKE_COLORS[strtolower($color)];
        }

        return $this->hex($color) ?? '#1e1e1e';
    }

    public function strokeStyle(mixed $style): string
    {
        return in_array($style, ['solid', 'dashed', 'dotted'], true) ? $style : 'solid';
    }

    private function arrowhead(mixed $head, ?string $default): ?string
    {
        if ($head === null || $head === 'none' || $head === false) {
            return $head === null ? $default : null;
        }

        return in_array($head, self::ARROWHEADS, true) ? $head : $default;
    }

    private function hex(mixed $color): ?string
    {
        return is_string($color) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color) ? strtolower($color) : null;
    }

    public function link(mixed $link): ?string
    {
        return is_string($link) && preg_match('#^https?://#i', $link) ? mb_substr($link, 0, 2000) : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function fontSize(array $item, int $default): int
    {
        $size = $item['fontSize'] ?? match ($item['size'] ?? null) {
            's' => 16, 'm' => 20, 'l' => 28, 'xl' => 36, default => $default,
        };

        return (int) max(8, min(120, (int) $size));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function coord(array $item, string $key): float
    {
        return is_numeric($item[$key] ?? null) ? (float) $item[$key] : 0.0;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     * @return array{0: float, 1: float}
     */
    private function pointsSize(array $points): array
    {
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);

        return [round(max($xs) - min($xs), 2), round(max($ys) - min($ys), 2)];
    }

    public function id(mixed $id): string
    {
        if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,48}$/', $id)) {
            return $id;
        }

        if ($id !== null && $id !== '') {
            throw new InvalidWhiteboard("Id inválido: \"{$id}\" (use letras, números, \"_\" e \"-\", até 48).");
        }

        return Str::random(20);
    }
}
