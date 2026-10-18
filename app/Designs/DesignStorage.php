<?php

namespace App\Designs;

use App\Models\Artifact;
use App\Models\DesignBoard;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Onde os arquivos de um design ficam no disco de artifacts:
 *   {uuid}/boards/{board_id}/{revision}.html   HTML atual de cada artboard
 *   {uuid}/snapshots/{numero}.zip              versões congeladas
 */
class DesignStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk(config('ward.artifacts.disk'));
    }

    public function boardPath(Artifact $design, DesignBoard $board, int $revision): string
    {
        return "{$design->uuid}/boards/{$board->id}/{$revision}.html";
    }

    public function snapshotPath(Artifact $design, int $number): string
    {
        return "{$design->uuid}/snapshots/{$number}.zip";
    }

    public function html(DesignBoard $board): string
    {
        return (string) $this->disk()->get($board->storage_path);
    }
}
