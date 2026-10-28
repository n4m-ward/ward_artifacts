<?php

namespace App\Actions\Whiteboards;

use App\Actions\Artifacts\PublishArtifact;
use App\Enums\ArtifactType;
use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\Project;
use App\Models\User;
use App\Whiteboards\WhiteboardEditor;
use App\Whiteboards\WhiteboardTemplates;

/**
 * Cria um quadro vazio (ou a partir de um template). Como todo artifact, nasce
 * visível só para o dono.
 */
class CreateWhiteboard
{
    public function __construct(private WhiteboardEditor $editor, private WhiteboardTemplates $templates) {}

    public function handle(User $owner, string $title, string $project, ?string $template = null): Artifact
    {
        $title = PublishArtifact::cleanTitle($title);
        $items = $template ? $this->templates->items($template) : null;

        $board = Artifact::create([
            'uuid' => Artifact::newUuid(),
            'type' => ArtifactType::Whiteboard,
            'user_id' => $owner->id,
            'project_id' => Project::resolveFor($owner, $project)->id,
            'title' => $title,
            'slug' => Artifact::slugFor($title),
            'visibility' => Visibility::Owner,
            'design_revision' => 0,
            'whiteboard_seq' => 0,
        ])->refresh();

        if ($items !== null) {
            $this->editor->add($board, $owner, $items, ['at' => ['x' => 0, 'y' => 0]]);
        }

        return $board->refresh();
    }
}
