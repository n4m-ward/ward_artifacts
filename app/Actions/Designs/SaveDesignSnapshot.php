<?php

namespace App\Actions\Designs;

use App\Designs\DesignCanvas;
use App\Designs\DesignStorage;
use App\Designs\InvalidDesign;
use App\Models\Artifact;
use App\Models\DesignSnapshot;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Congela o canvas atual (canvas.json + artboards) numa versão numerada.
 * Guarda as mais recentes (ward.designs.keep_snapshots).
 */
class SaveDesignSnapshot
{
    public function __construct(private DesignCanvas $canvas, private DesignStorage $storage) {}

    public function handle(Artifact $design, User $author, ?string $label = null): DesignSnapshot
    {
        if (! $design->isDesign() || ! $design->boards()->exists()) {
            throw new InvalidDesign('Não há artboards para salvar numa versão.');
        }

        $number = (int) $design->snapshots()->max('number') + 1;
        $temp = tempnam(sys_get_temp_dir(), 'ward-snapshot-');

        try {
            $boards = $this->canvas->writeZip($design, $temp);
            $path = $this->storage->snapshotPath($design, $number);
            $this->storage->disk()->writeStream($path, $stream = fopen($temp, 'rb'));
            fclose($stream);
            $size = (int) filesize($temp);
        } finally {
            @unlink($temp);
        }

        $snapshot = $design->snapshots()->create([
            'number' => $number,
            'label' => $label !== null ? Str::limit(Str::squish($label), 120, '') : null,
            'storage_path' => $path,
            'size_bytes' => $size,
            'board_count' => $boards,
            'created_by' => $author->id,
        ]);

        $keep = max(1, (int) config('ward.designs.keep_snapshots'));
        $design->snapshots()->where('number', '<=', $number - $keep)->get()->each(function (DesignSnapshot $old) {
            $this->storage->disk()->delete($old->storage_path);
            $old->delete();
        });

        return $snapshot;
    }
}
