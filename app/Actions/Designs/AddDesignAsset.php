<?php

namespace App\Actions\Designs;

use App\Designs\DesignStorage;
use App\Designs\InvalidDesign;
use App\Models\Artifact;
use App\Models\DesignAsset;
use App\Models\User;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Guarda uma imagem no design. O nome é o hash do conteúdo, então a mesma
 * imagem enviada duas vezes vira um arquivo só.
 */
class AddDesignAsset extends DesignMutation
{
    public const TYPES = [
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'image/avif' => 'avif', 'image/svg+xml' => 'svg',
    ];

    public const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private DesignStorage $storage) {}

    public function handle(Artifact $design, User $user, string $bytes, ?string $originalName = null): DesignAsset
    {
        $size = strlen($bytes);

        if ($size === 0) {
            throw new InvalidDesign('A imagem está vazia.');
        }

        if ($size > self::MAX_BYTES) {
            throw new InvalidDesign('Imagem acima de '.Number::fileSize(self::MAX_BYTES).'.');
        }

        $mime = $this->mime($bytes);
        $extension = self::TYPES[$mime] ?? throw new InvalidDesign('Formato não aceito. Use PNG, JPG, WebP, GIF, AVIF ou SVG.');

        if ($design->size_bytes + $size > (int) config('ward.designs.max_bytes')) {
            throw new InvalidDesign('O design passaria do limite de '.Number::fileSize((int) config('ward.designs.max_bytes')).'.');
        }

        $base = Str::slug(pathinfo((string) $originalName, PATHINFO_FILENAME)) ?: 'imagem';
        $name = Str::limit($base, 40, '').'-'.substr(sha1($bytes), 0, 10).'.'.$extension;

        $existing = $design->assets()->where('name', $name)->first();
        if ($existing) {
            return $existing;
        }

        [$width, $height] = $this->dimensions($bytes, $mime);

        return $this->mutate($design, function () use ($design, $user, $bytes, $name, $originalName, $mime, $size, $width, $height) {
            $this->storage->disk()->put($this->storage->assetPath($design, $name), $bytes);

            return $design->assets()->create([
                'name' => $name,
                'original_name' => $originalName ? Str::limit($originalName, 160, '') : null,
                'mime' => $mime,
                'size_bytes' => $size,
                'width' => $width,
                'height' => $height,
                'created_by' => $user->id,
            ]);
        });
    }

    public function remove(Artifact $design, DesignAsset $asset): void
    {
        $this->mutate($design, function () use ($design, $asset) {
            $this->storage->disk()->delete($this->storage->assetPath($design, $asset->name));
            $asset->delete();
        });
    }

    private function mime(string $bytes): string
    {
        $head = ltrim(substr($bytes, 0, 512));

        if (str_starts_with($head, '<svg') || (str_starts_with($head, '<?xml') && str_contains(substr($bytes, 0, 2048), '<svg'))) {
            return 'image/svg+xml';
        }

        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function dimensions(string $bytes, string $mime): array
    {
        if ($mime === 'image/svg+xml') {
            return [null, null];
        }

        $info = @getimagesizefromstring($bytes);

        return $info ? [(int) $info[0], (int) $info[1]] : [null, null];
    }
}
