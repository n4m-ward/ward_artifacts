<?php

namespace App\Actions\Artifacts;

use App\Artifacts\ArtifactIngestor;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Grava um envio como nova versão do artifact e passa a apontar para ela.
 * É o único caminho por onde o conteúdo de um artifact muda.
 */
class StoreArtifactVersion
{
    public function __construct(private ArtifactIngestor $ingestor) {}

    public function handle(Artifact $artifact, User $author, string $sourcePath): ArtifactVersion
    {
        $directory = $artifact->uuid.'/'.Str::lower((string) Str::ulid());

        $ingested = $this->ingestor->ingest($sourcePath, $directory);

        try {
            $version = DB::transaction(function () use ($artifact, $author, $ingested) {
                $version = $artifact->versions()->create([
                    'number' => (int) $artifact->versions()->max('number') + 1,
                    'kind' => $ingested->kind,
                    'storage_path' => $ingested->directory,
                    'entry_path' => $ingested->entryPath,
                    'size_bytes' => $ingested->sizeBytes,
                    'file_count' => $ingested->fileCount,
                    'sha256' => $ingested->sha256,
                    'created_by' => $author->id,
                ]);

                $artifact->update([
                    'current_version_id' => $version->id,
                    'size_bytes' => $ingested->sizeBytes,
                ]);

                return $version;
            });
        } catch (Throwable $e) {
            Storage::disk(config('ward.artifacts.disk'))->deleteDirectory($directory);

            throw $e;
        }

        $this->pruneOldVersions($artifact, $version);

        return $version;
    }

    private function pruneOldVersions(Artifact $artifact, ArtifactVersion $current): void
    {
        $keep = max(1, (int) config('ward.artifacts.keep_versions'));

        $artifact->versions()
            ->where('number', '<=', $current->number - $keep)
            ->get()
            ->each(function (ArtifactVersion $old) {
                Storage::disk(config('ward.artifacts.disk'))->deleteDirectory($old->storage_path);
                $old->delete();
            });
    }
}
