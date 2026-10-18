<?php

namespace App\Designs;

use App\Models\Artifact;
use App\Models\DesignBoard;
use App\Models\DesignNote;
use ZipArchive;

/**
 * Representação portátil de um canvas: canvas.json (layout) + um HTML por
 * artboard. É o que vai para os snapshots, para o download e para a IA.
 */
class DesignCanvas
{
    public function __construct(private DesignStorage $storage) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(Artifact $design): array
    {
        $boards = $design->orderedBoards()->get();

        return [
            'v' => 1,
            'title' => $design->title,
            'revision' => $design->design_revision,
            'pages' => $design->pages()->orderBy('sort')->orderBy('id')->get(['key', 'name'])->map(fn ($page) => ['key' => $page->key, 'name' => $page->name])->values()->all(),
            'tokens' => $design->design_tokens,
            'boards' => $boards->map(fn (DesignBoard $board) => $this->board($board))->values()->all(),
            'notes' => $design->notes()->orderBy('id')->get()->map(fn (DesignNote $note) => $note->toCanvas())->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function board(DesignBoard $board): array
    {
        return [
            'name' => $board->name,
            'title' => $board->title,
            'kind' => $board->kind->value,
            'page' => $board->page,
            'includes' => $board->includes ?? [],
            'x' => $board->x,
            'y' => $board->y,
            'w' => $board->w,
            'h' => $board->h,
            'interactive' => $board->interactive,
            'revision' => $board->revision,
            'file' => "boards/{$board->name}.html",
        ];
    }

    /**
     * Grava o canvas inteiro num .zip: canvas.json + boards/{nome}.html.
     */
    public function writeZip(Artifact $design, string $zipPath): int
    {
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $canvas = $this->toArray($design);
        $zip->addFromString('canvas.json', json_encode($canvas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // HTML como aparece no canvas: tokens aplicados e componentes expandidos.
        foreach ($design->orderedBoards()->get() as $board) {
            $zip->addFromString("boards/{$board->name}.html", app(BoardRenderer::class)->render($design, $board));
        }

        // Imagens ao lado dos HTMLs: "assets/{nome}" continua funcionando ao abrir o arquivo.
        foreach ($design->assets()->orderBy('id')->get() as $asset) {
            $path = $this->storage->assetPath($design, $asset->name);

            if ($this->storage->disk()->fileExists($path)) {
                $zip->addFromString("boards/assets/{$asset->name}", (string) $this->storage->disk()->get($path));
            }
        }

        $zip->close();

        return count($canvas['boards']);
    }
}
