<?php

namespace App\Actions\Designs;

use App\Designs\DesignStorage;
use App\Designs\InvalidDesign;
use App\Models\Artifact;
use App\Models\DesignBoard;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Leva um artboard de um design para o design system como componente (cria
 * ou atualiza pelo nome), com as imagens que ele usa.
 */
class PublishComponent extends DesignMutation
{
    public function __construct(private UpsertArtboard $upsert, private AddDesignAsset $assets, private DesignStorage $storage) {}

    public function handle(Artifact $design, DesignBoard $board, Artifact $system, User $user, ?string $name = null, ?string $description = null, ?string $tags = null): DesignBoard
    {
        if (! $system->isDesignSystem()) {
            throw new InvalidDesign('O destino não é um design system.');
        }

        $html = $this->storage->html($board);

        preg_match_all('#assets/([a-z0-9-]{1,60}\.(?:png|jpg|webp|gif|avif|svg))#', $html, $matches);
        foreach (array_unique($matches[1]) as $file) {
            $path = $this->storage->assetPath($design, $file);
            if ($design->id !== $system->id && $this->storage->disk()->fileExists($path)) {
                $asset = $this->assets->handle($system, $user, (string) $this->storage->disk()->get($path), $file);
                $html = str_replace("assets/{$file}", $asset->path(), $html);
            }
        }

        $component = $this->upsert->handle($system, $user, [
            'name' => $name ?: $board->name,
            'title' => $board->title,
            'kind' => $board->kind->value,
            'html' => $html,
            'w' => $board->w,
            'h' => $board->h,
            'interactive' => $board->interactive,
        ]);

        $this->describe($system, $component, $description, $tags);

        return $component->fresh();
    }

    public function describe(Artifact $system, DesignBoard $component, ?string $description, ?string $tags): void
    {
        $changes = array_filter([
            'description' => $description !== null ? Str::limit(trim($description), 2000, '') : null,
            'tags' => $tags !== null ? Str::limit(collect(preg_split('/[,;]+/', $tags))->map(fn ($tag) => trim($tag))->filter()->unique()->implode(', '), 200, '') : null,
        ], fn ($value) => $value !== null);

        if ($changes !== []) {
            $this->mutate($system, fn () => $component->forceFill($changes)->save());
        }
    }
}
