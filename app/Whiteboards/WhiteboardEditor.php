<?php

namespace App\Whiteboards;

use App\Models\Artifact;
use App\Models\User;

/**
 * Mudanças no quadro feitas pelo servidor (ferramentas MCP e templates): criar
 * itens no formato simples, mudar, apagar, desenhar diagramas com layout e
 * organizar. Tudo vira elementos do Excalidraw gravados com WhiteboardStore::put.
 */
class WhiteboardEditor
{
    private const PLACE_GAP = 120;

    public function __construct(
        private WhiteboardStore $store,
        private ElementFactory $factory,
        private DiagramLayout $layout,
        private WhiteboardFiles $files,
    ) {}

    /**
     * Elementos do quadro por id (sem os apagados).
     *
     * @return array<string, array<string, mixed>>
     */
    public function existing(Artifact $board): array
    {
        $byId = [];

        foreach ($this->store->all($board) as $element) {
            $byId[$element['id']] = $element;
        }

        return $byId;
    }

    /**
     * Cria itens. Sem x/y, ficam lado a lado à direita do que já existe; com
     * $place, x/y viram deslocamentos a partir do lugar escolhido.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|string|null  $place
     * @return array{ids: list<string>, elements: list<array<string, mixed>>, seq: int}
     */
    public function add(Artifact $board, ?User $user, array $items, array|string|null $place = null): array
    {
        if ($items === []) {
            throw new InvalidWhiteboard('Nada para criar.');
        }

        if (count($items) > 500) {
            throw new InvalidWhiteboard('Até 500 itens por pedido.');
        }

        $existing = $this->existing($board);

        foreach ($items as $index => $item) {
            if (($item['type'] ?? null) === 'image') {
                $items[$index] = $this->imageItem($board, $user, (array) $item);
            }
        }

        [$items, $frame] = $this->place($items, $existing, $place);
        $elements = $this->factory->make($items, $existing);

        if ($frame !== null) {
            $byId = array_column($elements, null, 'id') + $existing;
            $out = array_column($elements, null, 'id');
            foreach ($items as $item) {
                if (! in_array($item['type'] ?? null, ['frame'], true) && isset($item['id'])) {
                    $this->factory->setFrame($item['id'], $frame, $byId, $out);
                }
            }
            $out = $this->growFrame($frame, $byId, $out);
            $elements = array_values($out);
        }

        $saved = $this->store->put($board, $user, $elements);

        return ['ids' => array_values(array_filter(array_map(fn ($item) => $item['id'] ?? null, $items))), 'elements' => $elements, 'seq' => $saved['seq']];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $existing
     * @param  array<string, mixed>|string|null  $place
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    private function place(array $items, array $existing, array|string|null $place): array
    {
        foreach ($items as $index => $item) {
            $items[$index]['id'] = $this->factory->id($item['id'] ?? null);
        }

        $frame = null;
        $grid = $place === 'grid' || (is_array($place) && ($place['grid'] ?? false));

        if (is_array($place) && isset($place['inside'])) {
            $frame = (string) $place['inside'];
            $target = $existing[$frame] ?? null;
            if (($target['type'] ?? null) !== 'frame') {
                throw new InvalidWhiteboard("place.inside: \"{$frame}\" não é um frame do quadro.");
            }
            $origin = [$target['x'] + 40, $target['y'] + 60];
        } elseif (is_array($place) && (isset($place['right_of']) || isset($place['below']))) {
            $ref = (string) ($place['right_of'] ?? $place['below']);
            $target = $existing[$ref] ?? throw new InvalidWhiteboard("place: o elemento \"{$ref}\" não existe.");
            $origin = isset($place['right_of'])
                ? [$target['x'] + $target['width'] + self::PLACE_GAP, $target['y']]
                : [$target['x'], $target['y'] + $target['height'] + self::PLACE_GAP];
        } elseif (is_array($place) && isset($place['at']['x'], $place['at']['y'])) {
            $origin = [(float) $place['at']['x'], (float) $place['at']['y']];
        } elseif ($place !== null || $this->anyWithoutPosition($items)) {
            $origin = $this->freeSpot($existing);
        } else {
            return [$items, null];
        }

        $relative = $place !== null;
        $cursor = 0.0;
        $column = 0;
        $row = 0.0;
        $rowHeight = 0.0;
        $columns = max(1, (int) ceil(sqrt(count(array_filter($items, fn ($item) => $this->positioned($item))))));

        foreach ($items as $index => $item) {
            if (! $this->positioned($item)) {
                continue;
            }

            [$w, $h] = $this->itemSize($item);

            if ($grid) {
                if ($column === $columns) {
                    $column = 0;
                    $row += $rowHeight + 40;
                    $rowHeight = 0.0;
                }
                $items[$index]['x'] = $origin[0] + $column * ($w + 40);
                $items[$index]['y'] = $origin[1] + $row;
                $rowHeight = max($rowHeight, $h);
                $column++;
            } elseif (! isset($item['x']) || ! isset($item['y'])) {
                $items[$index]['x'] = $origin[0] + $cursor;
                $items[$index]['y'] = $origin[1];
                $cursor += $w + 40;
            } elseif ($relative) {
                $items[$index]['x'] = $origin[0] + (float) $item['x'];
                $items[$index]['y'] = $origin[1] + (float) $item['y'];
            }
        }

        return [$items, $frame];
    }

    /**
     * Item que ocupa lugar (formas, textos, imagens e frames; setas seguem os elementos).
     *
     * @param  array<string, mixed>  $item
     */
    private function positioned(array $item): bool
    {
        $type = $item['type'] ?? null;

        return ! in_array($type, ['arrow', 'line'], true) || (! isset($item['from']) && ! isset($item['to']));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function anyWithoutPosition(array $items): bool
    {
        foreach ($items as $item) {
            if ($this->positioned($item) && ($item['type'] ?? null) !== 'frame' && (! isset($item['x']) || ! isset($item['y']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: float, 1: float}
     */
    private function itemSize(array $item): array
    {
        $type = (string) ($item['type'] ?? 'rectangle');

        if (isset($item['width'], $item['height'])) {
            return [(float) $item['width'], (float) $item['height']];
        }

        return match (true) {
            in_array($type, ElementFactory::SHAPES, true) => $this->layout->size($type, (string) ($item['text'] ?? '')),
            $type === 'text' => $this->factory->measure((string) ($item['text'] ?? ''), (int) ($item['fontSize'] ?? 20)),
            $type === 'frame' => [800.0, 600.0],
            default => [200.0, 120.0],
        };
    }

    /**
     * Um lugar livre: à direita de tudo que já existe, alinhado ao topo.
     *
     * @param  array<string, array<string, mixed>>  $existing
     * @return array{0: float, 1: float}
     */
    public function freeSpot(array $existing): array
    {
        if ($existing === []) {
            return [0.0, 0.0];
        }

        $box = $this->factory->bounds(array_values($existing));

        return [$box[2] + self::PLACE_GAP * 1.5, $box[1]];
    }

    /**
     * Imagem por data: URL: grava o arquivo e devolve o item com fileId e tamanho.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function imageItem(Artifact $board, ?User $user, array $item): array
    {
        $src = (string) ($item['src'] ?? '');

        if (! str_starts_with($src, 'data:')) {
            throw new InvalidWhiteboard('Imagem: envie src como data:image/png;base64,... (até 4 MB).');
        }

        $file = $this->files->storeDataUrl($board, $user, $src);
        [$w, $h] = $file['size'];
        $scale = min(1, 480 / max(1, $w), 480 / max(1, $h));

        unset($item['src']);

        return $item + ['fileId' => $file['id'], 'width' => round($w * $scale), 'height' => round($h * $scale)];
    }

    /**
     * Muda elementos existentes. Cada mudança: {id, text?, color?, fill?, stroke?,
     * x?, y?, dx?, dy?, width?, height?, frame?, name?, link?, style?, fontSize?,
     * startArrow?, endArrow?, from?, to?, label?}.
     *
     * @param  list<array<string, mixed>>  $changes
     * @return array{ids: list<string>, seq: int}
     */
    public function update(Artifact $board, ?User $user, array $changes): array
    {
        if ($changes === []) {
            throw new InvalidWhiteboard('Nada para mudar.');
        }

        $byId = $this->existing($board);
        $out = [];
        $moved = [];

        foreach ($changes as $change) {
            $id = (string) ($change['id'] ?? '');
            $element = $out[$id] ?? $byId[$id] ?? throw new InvalidWhiteboard("Elemento \"{$id}\" não existe no quadro (veja get_whiteboard).");
            $element = $this->pending($element);
            $kind = $this->kind($element);

            // Posição e tamanho.
            $dx = isset($change['x']) ? (float) $change['x'] - $element['x'] : (float) ($change['dx'] ?? 0);
            $dy = isset($change['y']) ? (float) $change['y'] - $element['y'] : (float) ($change['dy'] ?? 0);

            if (isset($change['width']) || isset($change['height'])) {
                $element['width'] = max(10, (float) ($change['width'] ?? $element['width']));
                $element['height'] = max(10, (float) ($change['height'] ?? $element['height']));
                $moved[$id] = true;
            }

            // Cores e estilo.
            if (array_key_exists('color', $change)) {
                match ($kind) {
                    'sticky' => $element['backgroundColor'] = $this->factory->stickyColor($change['color']),
                    'text', 'arrow', 'line', 'freedraw' => $element['strokeColor'] = $this->factory->stroke($change['color']),
                    default => $element['backgroundColor'] = $this->factory->fill($change['color']),
                };
            }
            if (array_key_exists('fill', $change)) {
                $element['backgroundColor'] = $this->factory->fill($change['fill']);
            }
            if (array_key_exists('stroke', $change)) {
                $element['strokeColor'] = $this->factory->stroke($change['stroke']);
            }
            if (isset($change['style'])) {
                $element['strokeStyle'] = $this->factory->strokeStyle($change['style']);
            }
            if (array_key_exists('link', $change)) {
                $element['link'] = $this->factory->link($change['link']);
            }
            if (isset($change['name']) && $element['type'] === 'frame') {
                $element['name'] = mb_substr((string) $change['name'], 0, 120);
            }
            if ($element['type'] === 'arrow') {
                foreach (['startArrow' => 'startArrowhead', 'endArrow' => 'endArrowhead'] as $key => $field) {
                    if (array_key_exists($key, $change)) {
                        $element[$field] = in_array($change[$key], ElementFactory::ARROWHEADS, true) ? $change[$key] : null;
                    }
                }
            }
            if (isset($change['fontSize']) && $element['type'] === 'text') {
                $element['fontSize'] = (int) max(8, min(120, (int) $change['fontSize']));
            }

            $out[$id] = $byId[$id] = $element;

            // Texto: no próprio texto, no texto preso à forma ou no rótulo da seta.
            $text = $change['text'] ?? ($change['label'] ?? null);
            if ($text !== null) {
                $this->setText($id, (string) $text, $byId, $out);
            }

            // Religar uma seta.
            if (in_array($element['type'], ['arrow', 'line'], true) && (isset($change['from']) || isset($change['to']))) {
                foreach (['from' => 'startBinding', 'to' => 'endBinding'] as $key => $field) {
                    if (! isset($change[$key])) {
                        continue;
                    }
                    $target = (string) $change[$key];
                    if (! isset($byId[$target])) {
                        throw new InvalidWhiteboard("Seta {$id}: o elemento \"{$target}\" não existe.");
                    }
                    $out[$id][$field] = ['elementId' => $target, 'focus' => 0, 'gap' => 8];
                    $byId[$id] = $out[$id];
                    $this->factory->bind($target, $id, 'arrow', $byId, $out);
                }
                $moved[$id] = true;
            }

            if ($dx != 0 || $dy != 0) {
                $this->move($id, $dx, $dy, $byId, $out);
                $moved[$id] = true;
            }

            if (array_key_exists('frame', $change)) {
                $frame = $change['frame'] === null || $change['frame'] === '' ? null : (string) $change['frame'];
                if ($frame !== null && ($byId[$frame]['type'] ?? null) !== 'frame') {
                    throw new InvalidWhiteboard("\"{$frame}\" não é um frame do quadro.");
                }
                $this->factory->setFrame($id, $frame, $byId, $out);
            }
        }

        $this->reroute(array_keys($moved), $byId, $out);
        $saved = $this->store->put($board, $user, array_values($out));

        return ['ids' => array_values(array_unique(array_map(fn ($change) => (string) $change['id'], $changes))), 'seq' => $saved['seq']];
    }

    /**
     * Apaga elementos (com o texto preso a eles e, num frame, os filhos). Setas
     * ligadas a eles ficam soltas.
     *
     * @param  list<string>  $ids
     * @return array{ids: list<string>, seq: int}
     */
    public function delete(Artifact $board, ?User $user, array $ids): array
    {
        $byId = $this->existing($board);
        $remove = [];

        foreach ($ids as $id) {
            if (! isset($byId[$id])) {
                throw new InvalidWhiteboard("Elemento \"{$id}\" não existe no quadro.");
            }
            $remove[$id] = true;
            foreach ($byId[$id]['boundElements'] ?? [] as $bound) {
                if (($bound['type'] ?? null) === 'text') {
                    $remove[$bound['id']] = true;
                }
            }
            if ($byId[$id]['type'] === 'frame') {
                foreach ($byId as $child) {
                    if (($child['frameId'] ?? null) === $id) {
                        $remove[$child['id']] = true;
                    }
                }
            }
        }

        $out = [];

        foreach (array_keys($remove) as $id) {
            if (isset($byId[$id])) {
                $out[$id] = ['isDeleted' => true] + $byId[$id];
                $out[$id]['isDeleted'] = true;
            }
        }

        foreach ($byId as $id => $element) {
            if (isset($remove[$id])) {
                continue;
            }
            $changed = false;
            foreach (['startBinding', 'endBinding'] as $field) {
                if (isset($element[$field]['elementId']) && isset($remove[$element[$field]['elementId']])) {
                    $element[$field] = null;
                    $changed = true;
                }
            }
            if (! empty($element['boundElements'])) {
                $kept = array_values(array_filter($element['boundElements'], fn ($bound) => ! isset($remove[$bound['id'] ?? ''])));
                if (count($kept) !== count($element['boundElements'])) {
                    $element['boundElements'] = $kept ?: null;
                    $changed = true;
                }
            }
            if ($changed) {
                $out[$id] = $element;
            }
        }

        $saved = $this->store->put($board, $user, array_values($out));

        return ['ids' => array_keys($remove), 'seq' => $saved['seq']];
    }

    /**
     * Diagrama com layout automático (ver DiagramLayout), opcionalmente dentro de um frame novo.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return array{ids: list<string>, elements: list<array<string, mixed>>, seq: int, frame: ?string}
     */
    public function diagram(Artifact $board, ?User $user, string $kind, array $nodes, array $edges, string $direction = 'TB', ?string $frame = null, ?float $x = null, ?float $y = null): array
    {
        $existing = $this->existing($board);
        [$ox, $oy] = $x !== null && $y !== null ? [$x, $y] : $this->freeSpot($existing);
        $items = $this->layout->layout($kind, $nodes, $edges, $direction, 0, 0);

        // Leva o canto superior esquerdo do diagrama para o lugar escolhido.
        $shapes = array_filter($items, fn ($item) => isset($item['x']));
        $minX = min(array_column($shapes, 'x'));
        $minY = min(array_column($shapes, 'y'));
        $pad = $frame !== null ? 60 : 0;
        foreach ($items as $index => $item) {
            if (isset($item['x'])) {
                $items[$index]['x'] = $item['x'] - $minX + $ox + $pad;
                $items[$index]['y'] = $item['y'] - $minY + $oy + $pad + ($frame !== null ? 20 : 0);
            }
        }

        $frameId = null;
        if ($frame !== null) {
            $frameId = $this->factory->id(null);
            $items[] = ['id' => $frameId, 'type' => 'frame', 'name' => $frame, 'children' => array_column($shapes, 'id')];
        }

        $elements = $this->factory->make($items, $existing);
        $saved = $this->store->put($board, $user, $elements);

        return ['ids' => array_column($items, 'id'), 'elements' => $elements, 'seq' => $saved['seq'], 'frame' => $frameId];
    }

    /**
     * Organiza elementos: grid, alinhar (left, center, right, top, middle, bottom),
     * distribuir (horizontal, vertical) ou cluster (um frame por grupo, com os
     * elementos do grupo em grade dentro).
     *
     * @param  list<string>  $ids
     * @param  list<array{name: string, ids: list<string>}>  $groups
     * @return array{ids: list<string>, seq: int, frames: list<string>}
     */
    public function arrange(Artifact $board, ?User $user, array $ids, string $mode, array $groups = []): array
    {
        $byId = $this->existing($board);
        $out = [];
        $frames = [];

        if ($mode === 'cluster') {
            if ($groups === []) {
                throw new InvalidWhiteboard('cluster precisa de groups: [{name, ids}].');
            }
            $ids = array_merge(...array_map(fn ($group) => array_map('strval', (array) ($group['ids'] ?? [])), $groups));
        }

        foreach ($ids as $id) {
            if (! isset($byId[$id])) {
                throw new InvalidWhiteboard("Elemento \"{$id}\" não existe no quadro.");
            }
        }

        $targets = array_map(fn ($id) => $byId[$id], $ids);
        $box = $this->factory->bounds($targets);

        $moveTo = function (string $id, float $x, float $y) use (&$byId, &$out) {
            $this->move($id, $x - $byId[$id]['x'], $y - $byId[$id]['y'], $byId, $out);
        };

        switch ($mode) {
            case 'grid':
                $columns = (int) ceil(sqrt(count($ids)));
                $cellW = max(array_map(fn ($element) => $element['width'], $targets)) + 40;
                $cellH = max(array_map(fn ($element) => $element['height'], $targets)) + 40;
                foreach (array_values($ids) as $i => $id) {
                    $moveTo($id, $box[0] + ($i % $columns) * $cellW, $box[1] + intdiv($i, $columns) * $cellH);
                }
                break;
            case 'left': case 'center': case 'right':
                foreach ($ids as $id) {
                    $w = $byId[$id]['width'];
                    $x = match ($mode) {
                        'left' => $box[0], 'right' => $box[2] - $w, default => ($box[0] + $box[2]) / 2 - $w / 2
                    };
                    $moveTo($id, $x, $byId[$id]['y']);
                }
                break;
            case 'top': case 'middle': case 'bottom':
                foreach ($ids as $id) {
                    $h = $byId[$id]['height'];
                    $y = match ($mode) {
                        'top' => $box[1], 'bottom' => $box[3] - $h, default => ($box[1] + $box[3]) / 2 - $h / 2
                    };
                    $moveTo($id, $byId[$id]['x'], $y);
                }
                break;
            case 'horizontal': case 'vertical':
                $horizontal = $mode === 'horizontal';
                $sorted = $ids;
                usort($sorted, fn ($a, $b) => $horizontal ? $byId[$a]['x'] <=> $byId[$b]['x'] : $byId[$a]['y'] <=> $byId[$b]['y']);
                $total = array_sum(array_map(fn ($id) => $horizontal ? $byId[$id]['width'] : $byId[$id]['height'], $sorted));
                $space = count($sorted) > 1 ? (($horizontal ? $box[2] - $box[0] : $box[3] - $box[1]) - $total) / (count($sorted) - 1) : 0;
                $cursor = $horizontal ? $box[0] : $box[1];
                foreach ($sorted as $id) {
                    $horizontal ? $moveTo($id, $cursor, $byId[$id]['y']) : $moveTo($id, $byId[$id]['x'], $cursor);
                    $cursor += ($horizontal ? $byId[$id]['width'] : $byId[$id]['height']) + $space;
                }
                break;
            case 'cluster':
                $x = $box[0];
                foreach ($groups as $group) {
                    $members = array_values(array_map('strval', (array) ($group['ids'] ?? [])));
                    if ($members === []) {
                        continue;
                    }
                    $columns = (int) ceil(sqrt(count($members)));
                    $cellW = max(array_map(fn ($id) => $byId[$id]['width'], $members)) + 30;
                    $cellH = max(array_map(fn ($id) => $byId[$id]['height'], $members)) + 30;
                    foreach ($members as $i => $id) {
                        $moveTo($id, $x + 40 + ($i % $columns) * $cellW, $box[1] + 80 + intdiv($i, $columns) * $cellH);
                    }
                    $frameId = $this->factory->id(null);
                    $width = $columns * $cellW + 50;
                    $height = (int) ceil(count($members) / $columns) * $cellH + 100;
                    $frame = $this->factory->base($frameId, 'frame', $x, $box[1], $width, $height, [
                        'name' => mb_substr((string) ($group['name'] ?? 'Grupo'), 0, 120), 'strokeColor' => '#bbb',
                    ]);
                    $out[$frameId] = $byId[$frameId] = $frame;
                    foreach ($members as $id) {
                        $this->factory->setFrame($id, $frameId, $byId, $out);
                    }
                    $frames[] = $frameId;
                    $x += $width + 80;
                }
                break;
            default:
                throw new InvalidWhiteboard('mode deve ser grid, cluster, left, center, right, top, middle, bottom, horizontal ou vertical.');
        }

        $this->reroute($ids, $byId, $out);
        $saved = $this->store->put($board, $user, array_values($out));

        return ['ids' => $ids, 'seq' => $saved['seq'], 'frames' => $frames];
    }

    /**
     * Move um elemento, o texto preso a ele e, num frame, os filhos.
     *
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, array<string, mixed>>  $out
     */
    private function move(string $id, float $dx, float $dy, array &$byId, array &$out): void
    {
        if ($dx == 0 && $dy == 0) {
            return;
        }

        $targets = [$id];
        if (($byId[$id]['type'] ?? null) === 'frame') {
            foreach ($byId as $child) {
                if (($child['frameId'] ?? null) === $id && empty($child['containerId'])) {
                    $targets[] = $child['id'];
                }
            }
        }

        foreach ($targets as $target) {
            $element = $this->pending($out[$target] ?? $byId[$target]);
            $element['x'] += $dx;
            $element['y'] += $dy;
            $out[$target] = $byId[$target] = $element;

            foreach ($element['boundElements'] ?? [] as $bound) {
                if (($bound['type'] ?? null) === 'text' && isset($byId[$bound['id']])) {
                    $text = $this->pending($out[$bound['id']] ?? $byId[$bound['id']]);
                    $text['x'] += $dx;
                    $text['y'] += $dy;
                    $out[$bound['id']] = $byId[$bound['id']] = $text;
                }
            }
        }

        if (count($targets) > 1) {
            $this->reroute($targets, $byId, $out);
        }
    }

    /**
     * Recalcula as setas presas aos elementos que mudaram de lugar ou tamanho.
     *
     * @param  list<string>  $ids
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, array<string, mixed>>  $out
     */
    private function reroute(array $ids, array &$byId, array &$out): void
    {
        $changed = array_flip($ids);

        foreach ($byId as $id => $element) {
            if (! in_array($element['type'], ['arrow', 'line'], true)) {
                continue;
            }

            $start = $element['startBinding']['elementId'] ?? null;
            $end = $element['endBinding']['elementId'] ?? null;

            if (! isset($changed[$id]) && ! isset($changed[$start ?? '']) && ! isset($changed[$end ?? ''])) {
                continue;
            }

            if (($start !== null && ! isset($byId[$start])) || ($end !== null && ! isset($byId[$end])) || ($start === null && $end === null)) {
                continue;
            }

            $points = $element['points'] ?? [[0, 0], [0, 0]];
            $last = end($points);
            $from = $start !== null ? $byId[$start] : ['x' => $element['x'], 'y' => $element['y'], 'width' => 0, 'height' => 0, 'type' => 'point'];
            $to = $end !== null ? $byId[$end] : ['x' => $element['x'] + $last[0], 'y' => $element['y'] + $last[1], 'width' => 0, 'height' => 0, 'type' => 'point'];
            $route = count($points) === 4 ? 'elbow' : (count($points) === 3 && $element['roundness'] !== null ? 'curved' : 'straight');
            $arrow = $this->factory->route($this->pending($out[$id] ?? $element), $from, $to, $route);

            // O rótulo acompanha o meio da seta.
            foreach ($arrow['boundElements'] ?? [] as $bound) {
                if (($bound['type'] ?? null) === 'text' && isset($byId[$bound['id']])) {
                    $label = $this->pending($out[$bound['id']] ?? $byId[$bound['id']]);
                    $tail = end($arrow['points']);
                    $label['x'] = $arrow['x'] + $tail[0] / 2 - $label['width'] / 2;
                    $label['y'] = $arrow['y'] + $tail[1] / 2 - $label['height'] / 2;
                    $out[$bound['id']] = $byId[$bound['id']] = $label;
                }
            }

            $out[$id] = $byId[$id] = $arrow;
        }
    }

    /**
     * Troca o texto de um elemento (texto solto, texto da forma ou rótulo da seta).
     *
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, array<string, mixed>>  $out
     */
    private function setText(string $id, string $text, array &$byId, array &$out): void
    {
        $element = $out[$id] ?? $byId[$id];

        if ($element['type'] === 'text') {
            $element = $this->pending($element);
            $element['text'] = $element['originalText'] = $text;
            [$element['width'], $element['height']] = $this->factory->measure($text, (int) ($element['fontSize'] ?? 20));
            $out[$id] = $byId[$id] = $element;

            return;
        }

        foreach ($element['boundElements'] ?? [] as $bound) {
            if (($bound['type'] ?? null) === 'text' && isset($byId[$bound['id']])) {
                $label = $this->pending($out[$bound['id']] ?? $byId[$bound['id']]);
                $label['text'] = $label['originalText'] = $text;
                $out[$bound['id']] = $byId[$bound['id']] = $label;

                return;
            }
        }

        if (! in_array($element['type'], ['rectangle', 'ellipse', 'diamond', 'arrow'], true)) {
            throw new InvalidWhiteboard("Elemento {$id} ({$element['type']}) não tem texto.");
        }

        $textId = $id.'-text';
        $sticky = ($element['customData']['wardKind'] ?? null) === 'sticky';
        $lines = $this->factory->wrap($text, 20, max(40, $element['width'] - 24));
        $out[$textId] = $byId[$textId] = $this->factory->boundText($textId, $id, $lines, $text, 20, $element, $sticky ? 'left' : 'center', $sticky ? 'top' : 'middle');
        $this->factory->bind($id, $textId, 'text', $byId, $out);
    }

    /**
     * Frame que cresce para conter os filhos novos.
     *
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, array<string, mixed>>  $out
     * @return array<string, array<string, mixed>>
     */
    private function growFrame(string $frame, array $byId, array $out): array
    {
        $children = array_values(array_filter($byId, fn ($element) => ($element['frameId'] ?? null) === $frame));
        $box = $this->factory->bounds($children);
        $element = $this->pending($out[$frame] ?? $byId[$frame]);
        $right = max($element['x'] + $element['width'], $box[2] + 40);
        $bottom = max($element['y'] + $element['height'], $box[3] + 40);
        $element['width'] = $right - $element['x'];
        $element['height'] = $bottom - $element['y'];
        $out[$frame] = $element;

        return $out;
    }

    /**
     * Marca o elemento para o navegador medir o texto e reparar ligações ao abrir.
     *
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    private function pending(array $element): array
    {
        $element['customData'] = ['wardPending' => true] + (array) ($element['customData'] ?? []);
        $element['customData']['wardPending'] = true;

        return $element;
    }

    /**
     * Tipo como a IA vê: sticky (post-it), text, rectangle, ellipse, diamond, arrow...
     *
     * @param  array<string, mixed>  $element
     */
    public function kind(array $element): string
    {
        return ($element['customData']['wardKind'] ?? null) === 'sticky' ? 'sticky' : $element['type'];
    }
}
