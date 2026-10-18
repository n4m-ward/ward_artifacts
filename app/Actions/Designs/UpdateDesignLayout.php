<?php

namespace App\Actions\Designs;

use App\Designs\InvalidDesign;
use App\Models\Artifact;
use App\Models\DesignNote;

/**
 * Move, redimensiona e renomeia artboards e cria/edita/apaga notas, em lote.
 * Só toca no que vier na lista; o resto do canvas fica como está.
 */
class UpdateDesignLayout extends DesignMutation
{
    /**
     * @param  list<array<string, mixed>>  $boards  {name, x?, y?, w?, h?, title?, new_name?, interactive?}
     * @param  list<array<string, mixed>>  $notes  {key, kind?, x?, y?, w?, text?, color?, size?, bold?, page?, delete?}
     * @param  list<array<string, mixed>>  $pages  {key, name?, sort?, delete?} (vêm primeiro: artboards e notas já podem usar a página nova)
     */
    public function handle(Artifact $design, array $boards = [], array $notes = [], array $pages = []): void
    {
        foreach ($pages as $page) {
            if (! preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) ($page['key'] ?? ''))) {
                throw new InvalidDesign('Cada página precisa de uma key (letras, números, _ ou -, até 40).');
            }
        }

        if ($pages !== []) {
            $this->mutate($design, function () use ($design, $pages) {
                foreach ($pages as $change) {
                    $page = $design->pages()->where('key', $change['key'])->first();

                    if (! empty($change['delete'])) {
                        // O conteúdo da página apagada vai para a primeira.
                        $design->boards()->where('page', $change['key'])->update(['page' => null]);
                        $design->notes()->where('page', $change['key'])->update(['page' => null]);
                        $page?->delete();

                        continue;
                    }

                    if ($page === null && blank($change['name'] ?? null)) {
                        throw new InvalidDesign("A página \"{$change['key']}\" é nova e precisa de name.");
                    }

                    $design->pages()->updateOrCreate(['key' => $change['key']], array_filter([
                        'name' => isset($change['name']) ? mb_substr(trim((string) $change['name']), 0, 80) : null,
                        'sort' => isset($change['sort']) ? (int) $change['sort'] : ($page === null ? (int) $design->pages()->max('sort') + 1 : null),
                    ], fn ($value) => $value !== null && $value !== ''));
                }
            });
        }

        foreach ([...$boards, ...$notes] as $change) {
            if (array_key_exists('page', $change)) {
                static::pageKey($design, $change['page']);
            }
        }

        $existing = $design->boards()->get()->keyBy('name');

        foreach ($boards as $change) {
            if (! isset($existing[$change['name'] ?? ''])) {
                throw new InvalidDesign('Artboard não encontrado: "'.($change['name'] ?? '').'".');
            }

            if (isset($change['new_name']) && ! static::validName($change['new_name'])) {
                throw new InvalidDesign("Nome de artboard inválido: \"{$change['new_name']}\".");
            }

            if (isset($change['new_name']) && $change['new_name'] !== $change['name'] && isset($existing[$change['new_name']])) {
                throw new InvalidDesign("Já existe um artboard chamado \"{$change['new_name']}\".");
            }

            foreach (['w', 'h'] as $dimension) {
                if (isset($change[$dimension]) && ($change[$dimension] < 40 || $change[$dimension] > 8000)) {
                    throw new InvalidDesign("{$dimension} deve ficar entre 40 e 8000 px.");
                }
            }
        }

        foreach ($notes as $note) {
            if (! preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) ($note['key'] ?? ''))) {
                throw new InvalidDesign('Cada nota precisa de uma key (letras, números, _ ou -, até 40).');
            }

            if (isset($note['kind']) && ! in_array($note['kind'], DesignNote::KINDS, true)) {
                throw new InvalidDesign('kind da nota deve ser "title" ou "sticky".');
            }

            if (isset($note['color']) && ! in_array($note['color'], DesignNote::COLORS, true)) {
                throw new InvalidDesign('Cor de nota inválida. Use: '.implode(', ', DesignNote::COLORS).'.');
            }

            if (isset($note['size']) && ! in_array($note['size'], DesignNote::SIZES, true)) {
                throw new InvalidDesign('Tamanho de nota inválido. Use: '.implode(', ', DesignNote::SIZES).'.');
            }
        }

        $this->mutate($design, function () use ($design, $boards, $notes, $existing) {
            foreach ($boards as $change) {
                $board = $existing[$change['name']];
                $board->fill(array_filter([
                    'name' => $change['new_name'] ?? null,
                    'title' => $change['title'] ?? null,
                    'x' => $change['x'] ?? null,
                    'y' => $change['y'] ?? null,
                    'w' => $change['w'] ?? null,
                    'h' => $change['h'] ?? null,
                    'interactive' => $change['interactive'] ?? null,
                ], fn ($value) => $value !== null));

                if (array_key_exists('page', $change)) {
                    $board->page = static::pageKey($design, $change['page']);
                }

                $board->save();
            }

            foreach ($notes as $change) {
                $note = $design->notes()->where('key', $change['key'])->first();

                if (! empty($change['delete'])) {
                    $note?->delete();

                    continue;
                }

                if ($note === null && blank($change['text'] ?? null)) {
                    throw new InvalidDesign("A nota \"{$change['key']}\" é nova e precisa de text.");
                }

                $page = array_key_exists('page', $change) ? ['page' => static::pageKey($design, $change['page'])] : [];

                $design->notes()->updateOrCreate(['key' => $change['key']], $page + array_filter([
                    'kind' => $change['kind'] ?? ($note === null ? 'sticky' : null),
                    'x' => $change['x'] ?? null,
                    'y' => $change['y'] ?? null,
                    'w' => $change['w'] ?? null,
                    'text' => $change['text'] ?? null,
                    'color' => $change['color'] ?? null,
                    'size' => $change['size'] ?? null,
                    'bold' => isset($change['bold']) ? (bool) $change['bold'] : null,
                ], fn ($value) => $value !== null));
            }
        });
    }
}
