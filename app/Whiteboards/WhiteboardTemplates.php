<?php

namespace App\Whiteboards;

use Illuminate\Support\Str;

/**
 * Modelos prontos de quadro. Cada um é uma lista de itens no formato simples
 * (o mesmo da IA), com posições relativas ao canto onde o modelo é inserido.
 */
class WhiteboardTemplates
{
    public const NAMES = [
        'flowchart' => 'Fluxograma',
        'retro' => 'Retrospectiva',
        'kanban' => 'Kanban',
        'journey' => 'Jornada do usuário',
        'matrix' => 'Matriz 2×2',
        'mindmap' => 'Mapa mental',
        'brainstorm' => 'Brainstorm',
    ];

    public function __construct(private DiagramLayout $layout) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function items(string $name): array
    {
        $p = Str::lower(Str::random(6)).'-';

        return match ($name) {
            'flowchart' => $this->layout->layout('flowchart', [
                ['id' => $p.'start', 'text' => 'Início', 'shape' => 'ellipse', 'color' => 'green'],
                ['id' => $p.'step', 'text' => 'Etapa', 'color' => 'blue'],
                ['id' => $p.'decision', 'text' => 'Decisão?', 'shape' => 'diamond', 'color' => 'yellow'],
                ['id' => $p.'yes', 'text' => 'Caminho A', 'color' => 'blue'],
                ['id' => $p.'no', 'text' => 'Caminho B', 'color' => 'blue'],
                ['id' => $p.'end', 'text' => 'Fim', 'shape' => 'ellipse', 'color' => 'red'],
            ], [
                ['from' => $p.'start', 'to' => $p.'step'],
                ['from' => $p.'step', 'to' => $p.'decision'],
                ['from' => $p.'decision', 'to' => $p.'yes', 'label' => 'sim'],
                ['from' => $p.'decision', 'to' => $p.'no', 'label' => 'não'],
                ['from' => $p.'yes', 'to' => $p.'end'],
                ['from' => $p.'no', 'to' => $p.'end'],
            ]),
            'retro' => $this->columns($p, [
                ['O que foi bem', 'green', ['Entregamos no prazo']],
                ['O que pode melhorar', 'orange', ['Revisões demoraram']],
                ['Ações', 'blue', ['Combinar horário de revisão']],
            ]),
            'kanban' => $this->columns($p, [
                ['A fazer', 'gray', ['Tarefa 1', 'Tarefa 2']],
                ['Fazendo', 'yellow', ['Tarefa 3']],
                ['Revisão', 'purple', []],
                ['Feito', 'green', ['Tarefa 0']],
            ]),
            'journey' => $this->journey($p),
            'matrix' => $this->matrix($p),
            'mindmap' => $this->layout->layout('mindmap', [
                ['id' => $p.'root', 'text' => 'Tema central', 'color' => 'yellow'],
                ['id' => $p.'a', 'text' => 'Ideia 1', 'color' => 'blue'],
                ['id' => $p.'b', 'text' => 'Ideia 2', 'color' => 'green'],
                ['id' => $p.'c', 'text' => 'Ideia 3', 'color' => 'pink'],
                ['id' => $p.'d', 'text' => 'Ideia 4', 'color' => 'purple'],
                ['id' => $p.'a1', 'text' => 'Detalhe'],
                ['id' => $p.'c1', 'text' => 'Detalhe'],
            ], [
                ['from' => $p.'root', 'to' => $p.'a'], ['from' => $p.'root', 'to' => $p.'b'],
                ['from' => $p.'root', 'to' => $p.'c'], ['from' => $p.'root', 'to' => $p.'d'],
                ['from' => $p.'a', 'to' => $p.'a1'], ['from' => $p.'c', 'to' => $p.'c1'],
            ]),
            'brainstorm' => $this->brainstorm($p),
            default => throw new InvalidWhiteboard('Template desconhecido. Use: '.implode(', ', array_keys(self::NAMES)).'.'),
        };
    }

    /**
     * Colunas (frames) com post-its, como numa retrospectiva ou num kanban.
     *
     * @param  list<array{0: string, 1: string, 2: list<string>}>  $columns
     * @return list<array<string, mixed>>
     */
    private function columns(string $p, array $columns): array
    {
        $items = [];

        foreach ($columns as $i => [$title, $color, $notes]) {
            $x = $i * 340;
            $children = [];
            foreach ($notes as $j => $text) {
                $id = "{$p}c{$i}n{$j}";
                $items[] = ['id' => $id, 'type' => 'sticky', 'text' => $text, 'color' => $color, 'x' => $x + 40, 'y' => 80 + $j * 230];
                $children[] = $id;
            }
            $items[] = ['id' => "{$p}c{$i}", 'type' => 'frame', 'name' => $title, 'x' => $x, 'y' => 0, 'width' => 300, 'height' => 760, 'children' => $children];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journey(string $p): array
    {
        $stages = ['Descobre', 'Considera', 'Compra', 'Usa', 'Recomenda'];
        $rows = [['Ações', 'blue'], ['Pensamentos', 'purple'], ['Sentimentos', 'pink'], ['Oportunidades', 'green']];
        $items = [];

        foreach ($stages as $i => $stage) {
            $items[] = ['id' => "{$p}s{$i}", 'type' => 'rectangle', 'text' => $stage, 'fill' => '#e7f5ff', 'x' => 220 + $i * 240, 'y' => 0, 'width' => 220, 'height' => 70];
        }

        foreach ($rows as $r => [$row, $color]) {
            $items[] = ['id' => "{$p}r{$r}", 'type' => 'text', 'text' => $row, 'fontSize' => 24, 'x' => 0, 'y' => 130 + $r * 240 + 80];
            foreach ($stages as $i => $stage) {
                $items[] = ['id' => "{$p}r{$r}s{$i}", 'type' => 'sticky', 'text' => '', 'color' => $color, 'x' => 230 + $i * 240, 'y' => 110 + $r * 240];
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matrix(string $p): array
    {
        $quadrants = [['Alto impacto · Pouco esforço', 'green', 0, 0], ['Alto impacto · Muito esforço', 'yellow', 1, 0], ['Baixo impacto · Pouco esforço', 'blue', 0, 1], ['Baixo impacto · Muito esforço', 'red', 1, 1]];
        $items = [];

        foreach ($quadrants as $i => [$title, $color, $col, $row]) {
            $items[] = ['id' => "{$p}q{$i}", 'type' => 'frame', 'name' => $title, 'x' => $col * 520, 'y' => $row * 420, 'width' => 500, 'height' => 400];
            $items[] = ['id' => "{$p}q{$i}n", 'type' => 'sticky', 'text' => 'Ideia', 'color' => $color, 'x' => $col * 520 + 40, 'y' => $row * 420 + 60];
        }

        // Os post-its entram no frame do quadrante.
        foreach ($items as $index => $item) {
            if ($item['type'] === 'frame') {
                $items[$index]['children'] = [$item['id'].'n'];
            }
        }

        // Frames depois dos filhos.
        usort($items, fn ($a, $b) => ($a['type'] === 'frame') <=> ($b['type'] === 'frame'));

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function brainstorm(string $p): array
    {
        $items = [['id' => "{$p}topic", 'type' => 'text', 'text' => 'Pergunta do brainstorm', 'fontSize' => 36, 'x' => 0, 'y' => 0]];
        $colors = ['yellow', 'pink', 'green', 'blue', 'orange', 'purple'];

        foreach ($colors as $i => $color) {
            $items[] = ['id' => "{$p}n{$i}", 'type' => 'sticky', 'text' => '', 'color' => $color, 'x' => ($i % 3) * 230, 'y' => 90 + intdiv($i, 3) * 230];
        }

        return $items;
    }
}
