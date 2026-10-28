<?php

namespace App\Whiteboards;

use App\Models\Artifact;
use App\Models\User;
use App\Models\WhiteboardFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Imagens de um quadro (os "files" do Excalidraw). Ficam no disco de artifacts em
 * {uuid}/whiteboard/files/{fileId}.{ext} e são servidas pelo app com CSP sandbox.
 */
class WhiteboardFiles
{
    public const MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg'];

    public function disk(): Filesystem
    {
        return Storage::disk(config('ward.artifacts.disk'));
    }

    /**
     * Grava um data: URL. Devolve o fileId e as dimensões da imagem.
     *
     * @return array{id: string, size: array{0: int, 1: int}}
     */
    public function storeDataUrl(Artifact $board, ?User $user, string $dataUrl, ?string $fileId = null): array
    {
        if (! preg_match('#^data:([a-z+/.-]+);base64,(.+)$#s', $dataUrl, $match)) {
            throw new InvalidWhiteboard('Imagem inválida: esperado data:image/...;base64,...');
        }

        $mime = strtolower($match[1]);
        $bytes = base64_decode($match[2], true);

        if (! isset(self::MIMES[$mime]) || $bytes === false) {
            throw new InvalidWhiteboard('Imagem: só PNG, JPEG, WebP, GIF ou SVG.');
        }

        $max = (int) config('ward.whiteboards.max_file_bytes');

        if (strlen($bytes) > $max) {
            throw new InvalidWhiteboard('Imagem acima de '.intdiv($max, 1024 * 1024).' MB.');
        }

        $info = $mime === 'image/svg+xml' ? null : @getimagesizefromstring($bytes);

        if ($mime !== 'image/svg+xml' && $info === false) {
            throw new InvalidWhiteboard('O arquivo não é uma imagem válida.');
        }

        $fileId = $fileId !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $fileId) ? $fileId : sha1($bytes);
        $existing = $board->whiteboardFiles()->where('file_id', $fileId)->first();

        if ($existing === null) {
            $path = "{$board->uuid}/whiteboard/files/{$fileId}.".self::MIMES[$mime];
            $this->disk()->put($path, $bytes);
            $board->whiteboardFiles()->create([
                'file_id' => $fileId,
                'mime' => $mime,
                'storage_path' => $path,
                'size_bytes' => strlen($bytes),
                'created_by' => $user?->id,
            ]);
        }

        return ['id' => $fileId, 'size' => $info ? [(int) $info[0], (int) $info[1]] : [320, 240]];
    }

    /**
     * Link assinado de uma imagem (o quadro mostra mesmo para quem abriu por link público).
     */
    public function url(Artifact $board, WhiteboardFile $file, int $minutes = 180): string
    {
        return URL::temporarySignedRoute('whiteboards.files.show', now()->addMinutes($minutes), [
            'artifact' => $board->uuid,
            'file' => $file->file_id,
        ]);
    }

    /**
     * fileId → URL de todas as imagens do quadro.
     *
     * @return array<string, string>
     */
    public function urls(Artifact $board): array
    {
        return $board->whiteboardFiles()->orderBy('id')->limit(1000)->get()
            ->mapWithKeys(fn (WhiteboardFile $file) => [$file->file_id => $this->url($board, $file)])->all();
    }

    public static function newId(): string
    {
        return Str::random(40);
    }
}
