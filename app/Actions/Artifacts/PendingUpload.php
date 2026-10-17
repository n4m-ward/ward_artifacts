<?php

namespace App\Actions\Artifacts;

use App\Models\ArtifactUpload;
use Carbon\CarbonInterface;

final readonly class PendingUpload
{
    public function __construct(
        public ArtifactUpload $upload,
        public string $url,
        public CarbonInterface $expiresAt,
    ) {}
}
