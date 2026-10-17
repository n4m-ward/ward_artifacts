<?php

namespace App\Artifacts;

use Illuminate\Support\Number;
use RuntimeException;

/**
 * Envio recusado. A mensagem volta para a IA (MCP / upload), então diz o que corrigir.
 */
class InvalidArtifact extends RuntimeException
{
    public static function empty(): self
    {
        return new self('O arquivo enviado está vazio.');
    }

    public static function tooLarge(int $max): self
    {
        return new self('O artifact passa do limite de '.Number::fileSize($max).' (somando todos os arquivos).');
    }

    public static function notHtml(): self
    {
        return new self('O arquivo não parece ser HTML. Envie um .html único ou um .zip com index.html + assets.');
    }

    public static function invalidZip(): self
    {
        return new self('Não foi possível abrir o .zip enviado.');
    }

    public static function missingIndex(): self
    {
        return new self('O .zip precisa ter um index.html na raiz (ou numa única pasta de topo).');
    }

    public static function tooManyFiles(int $max): self
    {
        return new self("O .zip tem mais de {$max} arquivos.");
    }

    public static function unsafePath(string $path): self
    {
        return new self("Caminho não permitido no .zip: \"{$path}\". Use só caminhos relativos, sem \"..\".");
    }

    public static function symlink(string $path): self
    {
        return new self("O .zip contém um link simbólico, que não é permitido: \"{$path}\".");
    }

    public static function extensionNotAllowed(string $path): self
    {
        return new self("Tipo de arquivo não permitido no .zip: \"{$path}\".");
    }
}
