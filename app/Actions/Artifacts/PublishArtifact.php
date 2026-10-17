<?php

namespace App\Actions\Artifacts;

use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cria um artifact novo a partir de um envio. Todo artifact nasce visível
 * só para o dono: abrir para outras pessoas é feito só na tela do artifact.
 */
class PublishArtifact
{
    public function __construct(private StoreArtifactVersion $storeVersion) {}

    public function handle(User $owner, string $title, string $project, string $sourcePath): Artifact
    {
        $title = static::cleanTitle($title);

        return DB::transaction(function () use ($owner, $title, $project, $sourcePath) {
            $artifact = Artifact::create([
                'uuid' => Artifact::newUuid(),
                'user_id' => $owner->id,
                'project_id' => Project::resolveFor($owner, $project)->id,
                'title' => $title,
                'slug' => Artifact::slugFor($title),
                'visibility' => Visibility::Owner,
            ]);

            $this->storeVersion->handle($artifact, $owner, $sourcePath);

            return $artifact->refresh();
        });
    }

    public static function cleanTitle(string $title): string
    {
        $title = Str::squish($title);

        return Str::limit($title !== '' ? $title : 'Artifact sem título', 200, '');
    }
}
