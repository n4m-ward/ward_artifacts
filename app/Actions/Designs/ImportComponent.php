<?php

namespace App\Actions\Designs;

use App\Designs\DesignStorage;
use App\Designs\DesignSystems;
use App\Designs\InvalidDesign;
use App\Models\Artifact;
use App\Models\DesignBoard;
use App\Models\User;

/**
 * Traz um componente de um design system para um design:
 * - link: um artboard novo que só inclui o componente (muda junto com o original);
 * - copy: uma cópia do HTML para adaptar à vontade (as imagens usadas vêm junto).
 * O design passa a usar o tema (tokens) do design system, se ainda não usa nenhum.
 */
class ImportComponent extends DesignMutation
{
    public function __construct(
        private UpsertArtboard $upsert,
        private AddDesignAsset $assets,
        private DesignStorage $storage,
        private DesignSystems $systems,
    ) {}

    /**
     * @param  array{name?: ?string, x?: ?int, y?: ?int, page?: ?string}  $placement
     */
    public function handle(Artifact $design, User $user, Artifact $system, string $component, string $mode = 'link', array $placement = []): DesignBoard
    {
        if (! $system->isDesignSystem()) {
            throw new InvalidDesign('Isto não é um design system.');
        }

        if ($design->id === $system->id) {
            throw new InvalidDesign('O componente já está neste design system.');
        }

        $source = $system->boards()->where('name', $component)->first()
            ?? throw new InvalidDesign("Componente não encontrado em {$this->systems->ref($system)}: \"{$component}\".");

        if (! in_array($mode, ['link', 'copy'], true)) {
            throw new InvalidDesign('mode deve ser "link" ou "copy".');
        }

        $ref = $this->systems->ref($system);
        $html = $mode === 'link'
            ? "<!doctype html>\n<html lang=\"pt-BR\">\n<head>\n<meta charset=\"utf-8\">\n<title>{$source->name}</title>\n<style>body { margin: 0; }</style>\n</head>\n<body>\n<ward-include ds=\"{$ref}\" name=\"{$source->name}\"></ward-include>\n</body>\n</html>\n"
            : $this->copyAssets($system, $design, $user, $this->storage->html($source));

        $name = $this->freeName($design, trim((string) ($placement['name'] ?? '')) ?: $source->name);

        $board = $this->upsert->handle($design, $user, array_filter([
            'name' => $name,
            'title' => $source->title ?: $source->name,
            'kind' => $source->kind->value,
            'html' => $html,
            'w' => $source->w,
            'h' => $source->h,
            'x' => $placement['x'] ?? null,
            'y' => $placement['y'] ?? null,
            'interactive' => $source->interactive,
        ], fn ($value) => $value !== null) + (array_key_exists('page', $placement) ? ['page' => $placement['page']] : []));

        if ($design->uses_design_system_id === null) {
            $this->mutate($design, fn () => $design->forceFill(['uses_design_system_id' => $system->id])->save());
        }

        return $board;
    }

    /**
     * Imagens "assets/x" do componente copiadas para o design de destino.
     */
    private function copyAssets(Artifact $from, Artifact $to, User $user, string $html): string
    {
        preg_match_all('#assets/([a-z0-9-]{1,60}\.(?:png|jpg|webp|gif|avif|svg))#', $html, $matches);

        foreach (array_unique($matches[1]) as $name) {
            $path = $this->storage->assetPath($from, $name);

            if ($this->storage->disk()->fileExists($path) && ! $to->assets()->where('name', $name)->exists()) {
                try {
                    $asset = $this->assets->handle($to, $user, (string) $this->storage->disk()->get($path), $name);
                    $html = str_replace("assets/{$name}", $asset->path(), $html);
                } catch (InvalidDesign) {
                    // Imagem que não passa na checagem: a cópia segue sem ela.
                }
            }
        }

        return $html;
    }

    private function freeName(Artifact $design, string $name): string
    {
        if (! $design->boards()->where('name', $name)->exists()) {
            return $name;
        }

        for ($n = 2; ; $n++) {
            $candidate = mb_substr($name, 0, 55).' '.$n;

            if (! $design->boards()->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }
    }
}
