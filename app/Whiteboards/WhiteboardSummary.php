<?php

namespace App\Whiteboards;

use App\Models\Artifact;

/**
 * O quadro como a IA lê: um item por elemento "de verdade" (o texto preso a uma
 * forma vira o text da forma; o rótulo da seta vira o label), com tipo no
 * vocabulário das ferramentas (sticky, rectangle, arrow...), cor, posição, frame
 * e ligações. Quadro grande: resumo por frame, e o detalhe por frame ou região.
 */
class WhiteboardSummary
{
    public const DETAIL_LIMIT = 300;

    public function __construct(private WhiteboardStore $store, private WhiteboardEditor $editor) {}

    /**
     * @param  array{x: float, y: float, w: float, h: float}|null  $region
     * @return array<string, mixed>
     */
    public function read(Artifact $board, ?string $frame = null, ?array $region = null): array
    {
        $elements = $this->store->all($board);
        $byId = array_column($elements, null, 'id');
        $items = [];

        foreach ($elements as $element) {
            if (! empty($element['containerId']) && isset($byId[$element['containerId']])) {
                continue;
            }

            $items[] = $this->item($element, $byId);
        }

        $frames = array_values(array_map(fn ($item) => [
            'id' => $item['id'],
            'name' => $item['name'] ?? null,
            'x' => $item['x'], 'y' => $item['y'], 'w' => $item['w'], 'h' => $item['h'],
            'elements' => count(array_filter($items, fn ($other) => ($other['frame'] ?? null) === $item['id'])),
        ], array_filter($items, fn ($item) => $item['type'] === 'frame')));

        $filtered = $items;

        if ($frame !== null) {
            if (! isset($byId[$frame]) || $byId[$frame]['type'] !== 'frame') {
                throw new InvalidWhiteboard("Frame \"{$frame}\" não encontrado.");
            }
            $filtered = array_values(array_filter($items, fn ($item) => ($item['frame'] ?? null) === $frame || $item['id'] === $frame));
        } elseif ($region !== null) {
            $filtered = array_values(array_filter($items, fn ($item) => $item['x'] < $region['x'] + $region['w'] && $region['x'] < $item['x'] + $item['w']
                && $item['y'] < $region['y'] + $region['h'] && $region['y'] < $item['y'] + $item['h']));
        }

        $bounds = $elements ? array_map(fn ($value) => round($value), app(ElementFactory::class)->bounds($elements)) : null;
        $truncated = count($filtered) > self::DETAIL_LIMIT;

        return array_filter([
            'count' => count($items),
            'bounds' => $bounds ? ['x' => $bounds[0], 'y' => $bounds[1], 'w' => $bounds[2] - $bounds[0], 'h' => $bounds[3] - $bounds[1]] : null,
            'frames' => $frames,
            'elements' => $truncated ? array_slice($filtered, 0, self::DETAIL_LIMIT) : $filtered,
            'truncated' => $truncated ? 'Quadro grande: mostrei '.self::DETAIL_LIMIT.' de '.count($filtered).'. Peça um frame (frame) ou uma região (region) para ver o resto.' : null,
            'free_spot' => (fn ($spot) => ['x' => round($spot[0]), 'y' => round($spot[1])])($this->editor->freeSpot($byId)),
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, array<string, mixed>>  $byId
     * @return array<string, mixed>
     */
    private function item(array $element, array $byId): array
    {
        $kind = $this->editor->kind($element);
        $text = null;
        $label = null;

        foreach ($element['boundElements'] ?? [] as $bound) {
            if (($bound['type'] ?? null) === 'text' && isset($byId[$bound['id']])) {
                $value = $byId[$bound['id']]['originalText'] ?? $byId[$bound['id']]['text'] ?? '';
                in_array($element['type'], ['arrow', 'line'], true) ? $label = $value : $text = $value;
            }
        }

        if ($element['type'] === 'text') {
            $text = $element['originalText'] ?? $element['text'] ?? '';
        }

        $color = match (true) {
            $kind === 'sticky' => $this->name($element['backgroundColor'] ?? '', ElementFactory::STICKY_COLORS),
            in_array($kind, ['text', 'arrow', 'line', 'freedraw'], true) => $this->name($element['strokeColor'] ?? '', ElementFactory::STROKE_COLORS),
            default => $this->name($element['backgroundColor'] ?? '', ElementFactory::STICKY_COLORS),
        };

        return array_filter([
            'id' => $element['id'],
            'type' => $kind === 'embeddable' && str_contains((string) ($element['link'] ?? ''), '/w/artboard/') ? 'artboard' : $kind,
            'text' => $text !== null && $text !== '' ? $text : null,
            'name' => $element['type'] === 'frame' ? ($element['name'] ?? null) : null,
            'label' => $label !== null && $label !== '' ? $label : null,
            'color' => $color !== 'transparent' ? $color : null,
            'x' => (int) round($element['x']),
            'y' => (int) round($element['y']),
            'w' => (int) round($element['width']),
            'h' => (int) round($element['height']),
            'frame' => $element['frameId'] ?? null,
            'from' => $element['startBinding']['elementId'] ?? null,
            'to' => $element['endBinding']['elementId'] ?? null,
            'link' => $element['link'] ?? null,
            'locked' => ! empty($element['locked']) ? true : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, string>  $palette
     */
    private function name(string $color, array $palette): string
    {
        $found = array_search(strtolower($color), $palette, true);

        return $found !== false ? $found : $color;
    }
}
