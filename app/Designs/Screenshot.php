<?php

namespace App\Designs;

/**
 * Um print pronto para a IA: JPEG (bem menor que PNG no resultado do MCP).
 */
final class Screenshot
{
    public function __construct(
        public readonly string $jpeg,
        public readonly int $width,
        public readonly int $height,
    ) {}

    public static function fromPng(string $png, int $quality): self
    {
        $image = @imagecreatefromstring($png);

        if ($image === false) {
            throw new ScreenshotFailed('O print saiu num formato inválido.');
        }

        ob_start();
        imagejpeg($image, null, $quality);
        $jpeg = (string) ob_get_clean();

        $screenshot = new self($jpeg, imagesx($image), imagesy($image));
        imagedestroy($image);

        return $screenshot;
    }
}
