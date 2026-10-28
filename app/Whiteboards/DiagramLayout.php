<?php

namespace App\Whiteboards;

/**
 * Layout automático no servidor: a IA manda só nós e ligações e aqui saem as
 * posições. Fluxograma em camadas (como o dagre: camada pelo caminho mais longo,
 * ordem pelo baricentro para cruzar menos), árvore/organograma, mapa mental (filhos
 * dos dois lados da raiz) e grade. O resultado são itens para o ElementFactory.
 */
class DiagramLayout
{
    public const KINDS = ['flowchart', 'tree', 'org', 'mindmap', 'grid'];

    private const GAP_MAIN = 90;

    private const GAP_CROSS = 50;

    /** Distância do contorno das setas de volta até as formas (cabe no padding do frame). */
    private const DETOUR_GAP = 30;

    /** Camada de cada nó no último fluxograma (para achar as setas que voltam). */
    private array $layers = [];

    public function __construct(private ElementFactory $factory) {}

    /**
     * @param  list<array<string, mixed>>  $nodes  {id, text, shape?, color?}
     * @param  list<array<string, mixed>>  $edges  {from, to, label?}
     * @return list<array<string, mixed>> itens para o ElementFactory (formas e setas)
     */
    public function layout(string $kind, array $nodes, array $edges, string $direction = 'TB', float $originX = 0, float $originY = 0): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidWhiteboard('kind deve ser flowchart, tree, org, mindmap ou grid.');
        }

        if ($nodes === []) {
            throw new InvalidWhiteboard('O diagrama precisa de ao menos um nó.');
        }

        if (count($nodes) > 300) {
            throw new InvalidWhiteboard('Até 300 nós por diagrama.');
        }

        $byId = [];

        foreach ($nodes as $index => $node) {
            $id = $this->factory->id($node['id'] ?? null);

            if (isset($byId[$id])) {
                throw new InvalidWhiteboard("Nó repetido: \"{$id}\".");
            }

            $shape = in_array($node['shape'] ?? null, ElementFactory::SHAPES, true) ? $node['shape'] : ($kind === 'mindmap' && $index === 0 ? 'ellipse' : ($kind === 'grid' ? 'sticky' : 'rectangle'));
            [$w, $h] = $this->size($shape, (string) ($node['text'] ?? ''));
            $byId[$id] = ['id' => $id, 'node' => $node, 'shape' => $shape, 'w' => $w, 'h' => $h, 'x' => 0.0, 'y' => 0.0];
        }

        $links = [];

        foreach ($edges as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');

            if (! isset($byId[$from]) || ! isset($byId[$to])) {
                throw new InvalidWhiteboard("Ligação {$from} → {$to}: os dois nós precisam estar em nodes.");
            }

            if ($from !== $to) {
                $links[] = ['from' => $from, 'to' => $to, 'edge' => $edge];
            }
        }

        $horizontal = strtoupper($direction) === 'LR';

        match ($kind) {
            'flowchart' => $this->layered($byId, $links, $horizontal),
            'tree' => $this->tree($byId, $links, true),
            'org' => $this->tree($byId, $links, false),
            'mindmap' => $this->mindmap($byId, $links),
            'grid' => $this->grid($byId),
        };

        $items = [];

        foreach ($byId as $id => $box) {
            $node = $box['node'];
            $items[] = array_filter([
                'id' => $id,
                'type' => $box['shape'],
                'text' => (string) ($node['text'] ?? ''),
                'x' => round($originX + $box['x'], 2),
                'y' => round($originY + $box['y'], 2),
                'width' => $box['w'],
                'height' => $box['h'],
                'color' => $node['color'] ?? null,
                'fill' => $node['fill'] ?? ($box['shape'] === 'sticky' ? null : ($node['color'] ?? null)),
                'stroke' => $node['stroke'] ?? null,
                'link' => $node['link'] ?? null,
            ], fn ($value) => $value !== null);
        }

        $route = match ($kind) {
            'tree', 'org' => 'elbow',
            'mindmap' => 'curved',
            default => 'straight',
        };

        // Fluxograma: seta que volta para uma camada anterior contorna o diagrama
        // (por baixo no LR, pela direita no TB) em vez de cruzar as formas do caminho.
        $detour = null;
        $backs = 0;
        if ($kind === 'flowchart') {
            $detour = $horizontal
                ? max(array_map(fn ($box) => $box['y'] + $box['h'], $byId))
                : max(array_map(fn ($box) => $box['x'] + $box['w'], $byId));
        }

        foreach ($links as $index => $link) {
            $edge = $link['edge'];
            $back = $detour !== null && ($this->layers[$link['to']] ?? 0) <= ($this->layers[$link['from']] ?? 0);
            $items[] = array_filter([
                'id' => isset($edge['id']) ? $this->factory->id($edge['id']) : "{$link['from']}-{$link['to']}-{$index}",
                'type' => 'arrow',
                'from' => $link['from'],
                'to' => $link['to'],
                'label' => $edge['label'] ?? null,
                'route' => $back ? 'around' : ($edge['route'] ?? $route),
                'detour' => $back ? round(($horizontal ? $originY : $originX) + $detour + self::DETOUR_GAP + 20 * $backs++, 2) : null,
                'side' => $back ? ($horizontal ? 'below' : 'right') : null,
                'style' => $edge['style'] ?? null,
                'endArrow' => $kind === 'mindmap' ? 'none' : ($edge['endArrow'] ?? null),
                'color' => $edge['color'] ?? null,
            ], fn ($value) => $value !== null);
        }

        return $items;
    }

    /**
     * Largura e altura de um nó pelo texto (igual ao ElementFactory).
     *
     * @return array{0: float, 1: float}
     */
    public function size(string $shape, string $text): array
    {
        if ($shape === 'sticky') {
            $lines = $this->factory->wrap($text, 20, 176);
            [, $th] = $this->factory->measure(implode("\n", $lines), 20);

            return [200.0, max(200.0, $th + 40)];
        }

        [$tw] = $this->factory->measure($text, 20);
        $w = max(160, min(420, $tw + 48));
        $lines = $this->factory->wrap($text, 20, $w - 24);
        [, $th] = $this->factory->measure(implode("\n", $lines), 20);
        $h = max(80, $th + 40);

        return match ($shape) {
            'diamond' => [$w * 1.5, $h * 1.5],
            'ellipse' => [$w * 1.25, $h * 1.25],
            default => [(float) $w, (float) $h],
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $byId
     * @param  list<array{from: string, to: string}>  $links
     */
    private function layered(array &$byId, array $links, bool $horizontal): void
    {
        $ids = array_keys($byId);
        $out = array_fill_keys($ids, []);

        foreach ($links as $link) {
            $out[$link['from']][] = $link['to'];
        }

        // Tira os ciclos (só para calcular camadas): arestas que voltam na busca em profundidade.
        $state = [];
        $dag = array_fill_keys($ids, []);
        $visit = function ($id) use (&$visit, &$state, &$dag, $out) {
            $state[$id] = 1;
            foreach ($out[$id] as $next) {
                if (($state[$next] ?? 0) === 1) {
                    continue;
                }
                $dag[$id][] = $next;
                if (! isset($state[$next])) {
                    $visit($next);
                }
            }
            $state[$id] = 2;
        };

        foreach ($ids as $id) {
            if (! isset($state[$id])) {
                $visit($id);
            }
        }

        // Camada = caminho mais longo a partir de uma origem.
        $layer = array_fill_keys($ids, 0);
        $indegree = array_fill_keys($ids, 0);
        foreach ($dag as $from => $targets) {
            foreach ($targets as $to) {
                $indegree[$to]++;
            }
        }
        $queue = array_values(array_filter($ids, fn ($id) => $indegree[$id] === 0));
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($dag[$id] as $to) {
                $layer[$to] = max($layer[$to], $layer[$id] + 1);
                if (--$indegree[$to] === 0) {
                    $queue[] = $to;
                }
            }
        }

        $this->layers = $layer;
        $layers = [];
        foreach ($ids as $id) {
            $layers[$layer[$id]][] = $id;
        }
        ksort($layers);
        $layers = array_values($layers);

        // Ordem dentro da camada: baricentro dos vizinhos, descendo e subindo.
        $preds = array_fill_keys($ids, []);
        foreach ($dag as $from => $targets) {
            foreach ($targets as $to) {
                $preds[$to][] = $from;
            }
        }

        for ($sweep = 0; $sweep < 4; $sweep++) {
            $down = $sweep % 2 === 0;
            $range = $down ? range(1, count($layers) - 1) : range(count($layers) - 2, 0);
            if (count($layers) < 2) {
                break;
            }
            foreach ($range as $i) {
                $ref = $layers[$down ? $i - 1 : $i + 1];
                $position = array_flip($ref);
                $scores = [];
                foreach ($layers[$i] as $order => $id) {
                    $neighbors = $down ? $preds[$id] : $dag[$id];
                    $known = array_values(array_filter(array_map(fn ($n) => $position[$n] ?? null, $neighbors), fn ($p) => $p !== null));
                    $scores[$id] = $known ? array_sum($known) / count($known) : $order;
                }
                $current = $layers[$i];
                usort($current, fn ($a, $b) => $scores[$a] <=> $scores[$b]);
                $layers[$i] = $current;
            }
        }

        // Coordenadas: cada camada numa linha (TB) ou coluna (LR), centralizada.
        $main = 0.0;
        foreach ($layers as $row) {
            $thickness = max(array_map(fn ($id) => $horizontal ? $byId[$id]['w'] : $byId[$id]['h'], $row));
            $span = array_sum(array_map(fn ($id) => $horizontal ? $byId[$id]['h'] : $byId[$id]['w'], $row)) + self::GAP_CROSS * (count($row) - 1);
            $cross = -$span / 2;
            foreach ($row as $id) {
                $box = &$byId[$id];
                if ($horizontal) {
                    $box['x'] = $main + ($thickness - $box['w']) / 2;
                    $box['y'] = $cross;
                    $cross += $box['h'] + self::GAP_CROSS;
                } else {
                    $box['x'] = $cross;
                    $box['y'] = $main + ($thickness - $box['h']) / 2;
                    $cross += $box['w'] + self::GAP_CROSS;
                }
                unset($box);
            }
            $main += $thickness + self::GAP_MAIN;
        }
    }

    /**
     * Árvore: LR (tree) ou de cima para baixo (org). Cada subárvore ocupa a soma dos
     * filhos no eixo cruzado; o pai fica no meio dos filhos.
     *
     * @param  array<string, array<string, mixed>>  $byId
     * @param  list<array{from: string, to: string}>  $links
     */
    private function tree(array &$byId, array $links, bool $horizontal, float $sign = 1, ?array $only = null, ?string $root = null): void
    {
        [$children, $roots] = $this->forest(array_keys($byId), $links);

        if ($root !== null) {
            $roots = [$root];
        }

        $span = [];
        $measure = function ($id) use (&$measure, &$span, $children, $byId, $horizontal, $only) {
            $kids = $only !== null && isset($only[$id]) ? $only[$id] : $children[$id];
            $own = $horizontal ? $byId[$id]['h'] : $byId[$id]['w'];
            if ($kids === []) {
                return $span[$id] = $own;
            }
            $sum = array_sum(array_map($measure, $kids)) + self::GAP_CROSS * (count($kids) - 1);

            return $span[$id] = max($own, $sum);
        };

        $place = function ($id, $main, $cross) use (&$place, &$byId, &$span, $children, $horizontal, $sign, $only) {
            $kids = $only !== null && isset($only[$id]) ? $only[$id] : $children[$id];
            $box = &$byId[$id];
            $thickness = $horizontal ? $box['w'] : $box['h'];
            if ($horizontal) {
                $box['x'] = $sign > 0 ? $main : $main - $box['w'];
                $box['y'] = $cross + ($span[$id] - $box['h']) / 2;
            } else {
                $box['x'] = $cross + ($span[$id] - $box['w']) / 2;
                $box['y'] = $main;
            }
            unset($box);
            $sum = array_sum(array_map(fn ($kid) => $span[$kid], $kids)) + self::GAP_CROSS * max(0, count($kids) - 1);
            $offset = $cross + ($span[$id] - $sum) / 2;
            foreach ($kids as $kid) {
                $place($kid, $main + $sign * ($thickness + self::GAP_MAIN * 1.2), $offset);
                $offset += $span[$kid] + self::GAP_CROSS;
            }
        };

        $cross = 0.0;
        foreach ($roots as $rootId) {
            $measure($rootId);
            $place($rootId, 0.0, $cross);
            $cross += $span[$rootId] + self::GAP_CROSS * 2;
        }
    }

    /**
     * Mapa mental: raiz no centro, metade dos ramos à direita e metade à esquerda.
     *
     * @param  array<string, array<string, mixed>>  $byId
     * @param  list<array{from: string, to: string}>  $links
     */
    private function mindmap(array &$byId, array $links): void
    {
        [$children, $roots] = $this->forest(array_keys($byId), $links);
        $root = $roots[0];
        $branches = $children[$root];
        $right = array_slice($branches, 0, (int) ceil(count($branches) / 2));
        $left = array_slice($branches, count($right));

        $rootBox = $byId[$root];

        foreach ([[$right, 1], [$left, -1]] as [$side, $sign]) {
            if ($side === []) {
                continue;
            }
            $only = [$root => $side];
            $copy = $byId;
            $this->tree($copy, $links, true, $sign, $only, $root);
            // Alinha a raiz desta metade com a raiz real e copia só os nós do lado.
            $dy = $rootBox['y'] - $copy[$root]['y'];
            $dx = $sign > 0 ? $rootBox['x'] - $copy[$root]['x'] : $rootBox['x'] + $rootBox['w'] - ($copy[$root]['x'] + $copy[$root]['w']);
            $stack = $side;
            while ($stack !== []) {
                $id = array_pop($stack);
                $byId[$id]['x'] = $copy[$id]['x'] + $dx;
                $byId[$id]['y'] = $copy[$id]['y'] + $dy;
                array_push($stack, ...$children[$id]);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $byId
     */
    private function grid(array &$byId): void
    {
        $columns = (int) ceil(sqrt(count($byId)));
        $i = 0;
        $rowHeight = 0.0;
        $y = 0.0;
        foreach ($byId as $id => $box) {
            $column = $i % $columns;
            if ($column === 0 && $i > 0) {
                $y += $rowHeight + 40;
                $rowHeight = 0.0;
            }
            $byId[$id]['x'] = $column * 240.0;
            $byId[$id]['y'] = $y;
            $rowHeight = max($rowHeight, $box['h']);
            $i++;
        }
    }

    /**
     * Filhos de cada nó (primeiro pai vence) e as raízes (sem pai), na ordem dada.
     *
     * @param  list<string>  $ids
     * @param  list<array{from: string, to: string}>  $links
     * @return array{0: array<string, list<string>>, 1: list<string>}
     */
    private function forest(array $ids, array $links): array
    {
        $children = array_fill_keys($ids, []);
        $parent = [];

        foreach ($links as $link) {
            if (isset($parent[$link['to']])) {
                continue;
            }
            // Evita ciclo: não liga se "to" já é ancestral de "from".
            $walk = $link['from'];
            $cycle = false;
            while (isset($parent[$walk])) {
                if ($parent[$walk] === $link['to']) {
                    $cycle = true;
                    break;
                }
                $walk = $parent[$walk];
            }
            if ($cycle) {
                continue;
            }
            $parent[$link['to']] = $link['from'];
            $children[$link['from']][] = $link['to'];
        }

        $roots = array_values(array_filter($ids, fn ($id) => ! isset($parent[$id])));

        return [$children, $roots ?: [$ids[0]]];
    }
}
