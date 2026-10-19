<?php

namespace App\Designs;

use App\Models\Artifact;
use App\Models\DesignBoard;
use Illuminate\Support\Collection;

/**
 * Problemas de organização do canvas que a IA precisa ver e corrigir:
 * artboards sobrepostos (na mesma página) e ligações do protótipo para
 * artboards que não existem.
 */
class DesignLayoutCheck
{
    /**
     * @return array{overlaps: list<array{0: string, 1: string}>, broken_links: list<array{from: string, to: string}>}
     */
    public function check(Artifact $design): array
    {
        $boards = $design->orderedBoards()->get();

        return [
            'overlaps' => $this->overlaps($design, $boards),
            'broken_links' => $this->brokenLinks($boards),
        ];
    }

    /**
     * Pares de artboards da mesma página cujos retângulos se cruzam.
     *
     * @param  Collection<int, DesignBoard>  $boards
     * @return list<array{0: string, 1: string}>
     */
    public function overlaps(Artifact $design, Collection $boards): array
    {
        $pages = $design->pages()->orderBy('sort')->orderBy('id')->pluck('key');
        $pageOf = fn (DesignBoard $board) => $pages->contains($board->page) ? $board->page : $pages->first();
        $pairs = [];

        foreach ($boards->groupBy($pageOf) as $group) {
            $list = $group->values();

            for ($i = 0; $i < $list->count(); $i++) {
                for ($j = $i + 1; $j < $list->count(); $j++) {
                    if (static::intersects($list[$i], $list[$j])) {
                        $pairs[] = [$list[$i]->name, $list[$j]->name];
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * @param  Collection<int, DesignBoard>  $boards
     * @return list<array{from: string, to: string}>
     */
    public function brokenLinks(Collection $boards): array
    {
        $names = $boards->pluck('name')->flip();
        $broken = [];

        foreach ($boards as $board) {
            foreach ($board->links ?? [] as $link) {
                if (! isset($names[$link['to']])) {
                    $broken[] = ['from' => $board->name, 'to' => $link['to']];
                }
            }
        }

        return $broken;
    }

    /**
     * @param  DesignBoard|array{x: int, y: int, w: int, h: int}  $a
     * @param  DesignBoard|array{x: int, y: int, w: int, h: int}  $b
     */
    public static function intersects(DesignBoard|array $a, DesignBoard|array $b): bool
    {
        [$a, $b] = [(object) (is_array($a) ? $a : $a->only(['x', 'y', 'w', 'h'])), (object) (is_array($b) ? $b : $b->only(['x', 'y', 'w', 'h']))];

        return $a->x < $b->x + $b->w && $b->x < $a->x + $a->w
            && $a->y < $b->y + $b->h && $b->y < $a->y + $a->h;
    }

    /**
     * Aviso em texto para a resposta de uma ferramenta (vazio quando está tudo certo).
     *
     * @param  array{overlaps: list<array{0: string, 1: string}>, broken_links: list<array{from: string, to: string}>}  $check
     */
    public static function describe(array $check): string
    {
        $text = '';

        if ($check['overlaps'] !== []) {
            $text .= 'ATENÇÃO: artboards sobrepostos, um esconde o outro no canvas: '
                .collect($check['overlaps'])->map(fn ($pair) => "\"{$pair[0]}\" × \"{$pair[1]}\"")->implode(', ')
                .". Reposicione com update_layout (80 px entre artboards, 160 px entre fileiras).\n";
        }

        if ($check['broken_links'] !== []) {
            $text .= 'Ligações para artboards que não existem: '
                .collect($check['broken_links'])->map(fn ($link) => "\"{$link['from']}\" → \"{$link['to']}\"")->implode(', ')
                .". Crie a tela ou corrija o nome.\n";
        }

        return $text;
    }
}
