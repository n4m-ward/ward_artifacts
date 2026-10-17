<?php

namespace App\Artifacts;

use App\Enums\ArtifactKind;

final readonly class IngestedArtifact
{
    public function __construct(
        public ArtifactKind $kind,
        public string $directory,
        public string $entryPath,
        public int $sizeBytes,
        public int $fileCount,
        public string $sha256,
    ) {}
}
