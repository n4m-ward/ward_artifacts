<?php

namespace App\Actions\Artifacts;

use App\Models\Artifact;
use Illuminate\Support\Facades\Storage;

class DeleteArtifact
{
    public function handle(Artifact $artifact): void
    {
        Storage::disk(config('ward.artifacts.disk'))->deleteDirectory($artifact->uuid);

        $artifact->delete();
    }
}
