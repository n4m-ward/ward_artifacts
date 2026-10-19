<?php

namespace App\Actions\Designs;

use App\Designs\DesignLayoutCheck;
use App\Designs\DesignStorage;
use App\Designs\InvalidDesign;
use App\Enums\BoardKind;
use App\Models\Artifact;
use App\Models\DesignBoard;
use App\Models\User;
use Illuminate\Support\Number;

/**
 * Cria ou atualiza um artboard pelo nome. Sem x/y, um artboard novo vai para
 * a direita do último da primeira fileira. Cada mudança de HTML é uma nova
 * revisão do artboard (o arquivo anterior é apagado; o histórico fica nos
 * snapshots).
 */
class UpsertArtboard extends DesignMutation
{
    public function __construct(private DesignStorage $storage) {}

    /**
     * @param  array{name: string, html?: ?string, kind?: ?string, w?: ?int, h?: ?int, x?: ?int, y?: ?int, title?: ?string, interactive?: ?bool, page?: ?string}  $data
     */
    public function handle(Artifact $design, User $author, array $data): DesignBoard
    {
        $name = trim($data['name']);

        if (! static::validName($name)) {
            throw new InvalidDesign("Nome de artboard inválido: \"{$name}\". Use letras, números, espaço, _ ou - (até 60).");
        }

        $board = $design->boards()->where('name', $name)->first();
        $html = $data['html'] ?? null;

        if ($board === null) {
            $this->assertCanAdd($design);

            if (blank($html)) {
                throw new InvalidDesign('Um artboard novo precisa do HTML.');
            }

            foreach (['w', 'h'] as $dimension) {
                if (empty($data[$dimension])) {
                    throw new InvalidDesign("Um artboard novo precisa de {$dimension} (largura e altura em px).");
                }
            }
        }

        if ($html !== null) {
            $this->assertHtml($html);
        }

        $kind = isset($data['kind']) ? (BoardKind::tryFrom($data['kind']) ?? throw new InvalidDesign('kind deve ser "fixed" ou "page".')) : null;
        $page = array_key_exists('page', $data) ? static::pageKey($design, $data['page']) : false;

        foreach (['w', 'h'] as $dimension) {
            if (isset($data[$dimension]) && ($data[$dimension] < 40 || $data[$dimension] > 8000)) {
                throw new InvalidDesign("{$dimension} deve ficar entre 40 e 8000 px.");
            }
        }

        return $this->mutate($design, function () use ($design, $author, $data, $name, $board, $html, $kind, $page) {
            if ($board === null) {
                [$x, $y] = $this->nextPosition($design, $page === false ? null : $page, (int) $data['w'], (int) $data['h']);

                $board = $design->boards()->create([
                    'name' => $name,
                    'title' => $data['title'] ?? null,
                    'kind' => $kind ?? BoardKind::Fixed,
                    'x' => $data['x'] ?? $x,
                    'y' => $data['y'] ?? $y,
                    'w' => $data['w'],
                    'h' => $data['h'],
                    'interactive' => (bool) ($data['interactive'] ?? false),
                    'page' => $page === false ? null : $page,
                    'sort' => (int) $design->boards()->max('sort') + 1,
                    'revision' => 0,
                    'storage_path' => '',
                    'updated_by' => $author->id,
                ]);
            } else {
                $board->fill(array_filter([
                    'title' => $data['title'] ?? null,
                    'kind' => $kind,
                    'x' => $data['x'] ?? null,
                    'y' => $data['y'] ?? null,
                    'w' => $data['w'] ?? null,
                    'h' => $data['h'] ?? null,
                    'interactive' => $data['interactive'] ?? null,
                ], fn ($value) => $value !== null));
                $board->updated_by = $author->id;

                if ($page !== false) {
                    $board->page = $page;
                }
            }

            if ($html !== null) {
                $this->writeHtml($design, $board, $html);
            }

            $board->save();

            return $board;
        });
    }

    private function writeHtml(Artifact $design, DesignBoard $board, string $html): void
    {
        $previous = $board->storage_path;
        $revision = $board->revision + 1;
        $path = $this->storage->boardPath($design, $board, $revision);

        $this->storage->disk()->put($path, $html);

        $board->forceFill([
            'revision' => $revision,
            'storage_path' => $path,
            'size_bytes' => strlen($html),
            'includes' => DesignBoard::includesIn($html) ?: null,
            'links' => DesignBoard::linksIn($html) ?: null,
        ]);

        if ($previous !== '' && $previous !== $path) {
            $this->storage->disk()->delete($previous);
        }
    }

    private function assertCanAdd(Artifact $design): void
    {
        $max = (int) config('ward.designs.max_boards');

        if ($design->boards()->count() >= $max) {
            throw new InvalidDesign("O design já tem o máximo de {$max} artboards.");
        }
    }

    private function assertHtml(string $html): void
    {
        $max = (int) config('ward.designs.max_board_bytes');

        if (strlen($html) > $max) {
            throw new InvalidDesign('O HTML do artboard passa de '.Number::fileSize($max).'. Use imagens menores ou SVG.');
        }

        $head = substr($html, 0, 64 * 1024);

        if (str_contains($head, "\0") || ! preg_match('/<[a-z!][^>]*>/i', $head)) {
            throw new InvalidDesign('O conteúdo do artboard não parece ser HTML.');
        }
    }

    /**
     * À direita do artboard mais à direita da primeira fileira (y = 0) da página.
     * Se ali cruzar com outro artboard, vai para uma fileira nova abaixo de tudo.
     *
     * @return array{0: int, 1: int}
     */
    private function nextPosition(Artifact $design, ?string $page, int $w, int $h): array
    {
        $pages = $design->pages()->orderBy('sort')->orderBy('id')->pluck('key');
        $target = $pages->contains($page) ? $page : $pages->first();
        $boards = $design->boards()->get()->filter(fn (DesignBoard $b) => ($pages->contains($b->page) ? $b->page : $pages->first()) === $target);
        $gap = (int) config('ward.designs.gap');

        $right = $boards->where('y', 0)->map(fn (DesignBoard $b) => $b->x + $b->w)->max();
        $spot = ['x' => $right === null ? 0 : $right + $gap, 'y' => 0, 'w' => $w, 'h' => $h];

        if ($boards->contains(fn (DesignBoard $b) => DesignLayoutCheck::intersects($b, $spot))) {
            return [0, (int) $boards->map(fn (DesignBoard $b) => $b->y + $b->h)->max() + $gap * 2];
        }

        return [$spot['x'], 0];
    }
}
