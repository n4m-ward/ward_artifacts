<?php

namespace App\Actions\Designs;

use App\Designs\DesignStorage;
use App\Designs\InvalidDesign;
use App\Models\Artifact;
use App\Models\DesignComment;
use App\Models\DesignCommentImage;
use App\Models\User;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Imagens dos comentários do canvas: a pessoa envia (botão, colar, arrastar) antes de
 * publicar; ao publicar, as enviadas são ligadas à mensagem. Ficam à parte das imagens do
 * design ({uuid}/comments/{name}) e somem com o comentário.
 */
class DesignCommentImages
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_PER_MESSAGE = 4;

    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public function __construct(private DesignStorage $storage) {}

    public function upload(Artifact $design, User $user, string $bytes): DesignCommentImage
    {
        $size = strlen($bytes);

        if ($size === 0) {
            throw new InvalidDesign('A imagem está vazia.');
        }

        if ($size > self::MAX_BYTES) {
            throw new InvalidDesign('Imagem acima de '.Number::fileSize(self::MAX_BYTES).'.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        $extension = self::TYPES[$mime] ?? throw new InvalidDesign('Formato não aceito. Use PNG, JPG, WebP ou GIF.');
        $info = @getimagesizefromstring($bytes);

        $this->prunePending($design);

        $image = $design->commentImages()->create([
            'user_id' => $user->id,
            'name' => Str::lower(Str::random(24)).'.'.$extension,
            'mime' => $mime,
            'width' => $info ? (int) $info[0] : null,
            'height' => $info ? (int) $info[1] : null,
            'size_bytes' => $size,
        ]);

        $this->storage->disk()->put($image->storagePath($design), $bytes);

        return $image;
    }

    /**
     * Liga imagens enviadas (da própria pessoa, ainda não publicadas) a uma mensagem.
     *
     * @param  list<int>  $ids
     */
    public function attach(Artifact $design, User $user, DesignComment $comment, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return;
        }

        if (count($ids) > self::MAX_PER_MESSAGE) {
            throw new InvalidDesign('No máximo '.self::MAX_PER_MESSAGE.' imagens por comentário.');
        }

        $images = $design->commentImages()->whereIn('id', $ids)->whereNull('comment_id')->where('user_id', $user->id)->get()->keyBy('id');

        if ($images->count() !== count($ids)) {
            throw new InvalidDesign('Uma das imagens não está mais disponível. Envie de novo.');
        }

        foreach ($ids as $sort => $id) {
            $images[$id]->forceFill(['comment_id' => $comment->id, 'sort' => $sort])->save();
        }
    }

    /** Tira uma imagem enviada e ainda não publicada. */
    public function discard(Artifact $design, User $user, int $id): void
    {
        $image = $design->commentImages()->whereNull('comment_id')->where('user_id', $user->id)->findOrFail($id);
        $this->storage->disk()->delete($image->storagePath($design));
        $image->delete();
    }

    /**
     * Apaga os arquivos das imagens de uma mensagem (e das respostas, se for um tópico).
     * As linhas somem junto com o comentário (cascade).
     */
    public function deleteFiles(DesignComment $comment): void
    {
        $design = $comment->artifact;
        $ids = $comment->parent_id === null ? $comment->replies()->pluck('id')->push($comment->id) : collect([$comment->id]);

        foreach (DesignCommentImage::whereIn('comment_id', $ids)->get() as $image) {
            $this->storage->disk()->delete($image->storagePath($design));
        }
    }

    /** Enviadas e não publicadas há mais de um dia. */
    private function prunePending(Artifact $design): void
    {
        foreach ($design->commentImages()->whereNull('comment_id')->where('created_at', '<', now()->subDay())->get() as $image) {
            $this->storage->disk()->delete($image->storagePath($design));
            $image->delete();
        }
    }
}
