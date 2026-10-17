<?php

namespace App\Actions\Designs;

use App\Designs\DesignStorage;
use App\Designs\InvalidDesign;
use App\Models\Artifact;

class DeleteArtboard extends DesignMutation
{
    public function __construct(private DesignStorage $storage) {}

    public function handle(Artifact $design, string $name): void
    {
        $board = $design->boards()->where('name', $name)->first()
            ?? throw new InvalidDesign("Artboard não encontrado: \"{$name}\".");

        $this->mutate($design, function () use ($design, $board) {
            $board->delete();
            $this->storage->disk()->deleteDirectory("{$design->uuid}/boards/{$board->id}");
        });
    }
}
