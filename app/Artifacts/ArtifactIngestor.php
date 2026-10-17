<?php

namespace App\Artifacts;

use App\Enums\ArtifactKind;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Valida um envio (HTML único ou .zip com index.html + assets) e grava os
 * arquivos em um diretório do disco de artifacts.
 */
class ArtifactIngestor
{
    private const ENTRY = 'index.html';

    private const IGNORED = ['.DS_Store', 'Thumbs.db', 'desktop.ini'];

    public function ingest(string $sourcePath, string $directory): IngestedArtifact
    {
        $size = (int) filesize($sourcePath);
        $max = $this->maxBytes();

        if ($size === 0) {
            throw InvalidArtifact::empty();
        }

        if ($size > $max) {
            throw InvalidArtifact::tooLarge($max);
        }

        $sha256 = hash_file('sha256', $sourcePath);

        try {
            return $this->isZip($sourcePath)
                ? $this->ingestBundle($sourcePath, $directory, $sha256)
                : $this->ingestHtml($sourcePath, $directory, $sha256);
        } catch (Throwable $e) {
            $this->disk()->deleteDirectory($directory);

            throw $e;
        }
    }

    private function ingestHtml(string $sourcePath, string $directory, string $sha256): IngestedArtifact
    {
        $head = (string) file_get_contents($sourcePath, false, null, 0, 64 * 1024);

        if (str_contains($head, "\0") || ! preg_match('/<[a-z!][^>]*>/i', $head)) {
            throw InvalidArtifact::notHtml();
        }

        $stream = fopen($sourcePath, 'rb');
        $this->disk()->writeStream("{$directory}/".self::ENTRY, $stream);
        fclose($stream);

        return new IngestedArtifact(
            ArtifactKind::Html, $directory, self::ENTRY, (int) filesize($sourcePath), 1, $sha256,
        );
    }

    private function ingestBundle(string $sourcePath, string $directory, string $sha256): IngestedArtifact
    {
        $zip = new ZipArchive;

        if ($zip->open($sourcePath, ZipArchive::RDONLY) !== true) {
            throw InvalidArtifact::invalidZip();
        }

        try {
            $entries = $this->bundleEntries($zip);

            $totalBytes = 0;

            foreach ($entries as $path => $index) {
                $totalBytes += $this->extractEntry($zip, $index, "{$directory}/{$path}", $totalBytes);
            }
        } finally {
            $zip->close();
        }

        return new IngestedArtifact(
            ArtifactKind::Bundle, $directory, self::ENTRY, $totalBytes, count($entries), $sha256,
        );
    }

    /**
     * Lista os arquivos do zip (caminho final => índice), já validados.
     *
     * @return array<string, int>
     */
    private function bundleEntries(ZipArchive $zip): array
    {
        $max = $this->maxBytes();
        $maxFiles = (int) config('ward.artifacts.max_files');
        $allowed = config('ward.artifacts.bundle_extensions');

        $entries = [];
        $declaredBytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) $stat['name'];

            if (str_ends_with(str_replace('\\', '/', $name), '/')) {
                continue;
            }

            $path = $this->normalizePath($name);

            if ($this->isIgnored($path)) {
                continue;
            }

            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            if ($opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                throw InvalidArtifact::symlink($path);
            }

            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $allowed, true)) {
                throw InvalidArtifact::extensionNotAllowed($path);
            }

            $declaredBytes += (int) $stat['size'];
            if ($declaredBytes > $max) {
                throw InvalidArtifact::tooLarge($max);
            }

            $entries[$path] = $i;

            if (count($entries) > $maxFiles) {
                throw InvalidArtifact::tooManyFiles($maxFiles);
            }
        }

        return $this->stripSingleRootFolder($entries);
    }

    /**
     * Zipar uma pasta costuma gerar "pasta/index.html": nesse caso a pasta de
     * topo é removida dos caminhos.
     *
     * @param  array<string, int>  $entries
     * @return array<string, int>
     */
    private function stripSingleRootFolder(array $entries): array
    {
        if (isset($entries[self::ENTRY])) {
            return $entries;
        }

        $roots = array_unique(array_map(fn (string $path) => explode('/', $path, 2)[0], array_keys($entries)));

        if (count($roots) !== 1 || ! isset($entries[$roots[0].'/'.self::ENTRY])) {
            throw InvalidArtifact::missingIndex();
        }

        $prefix = strlen($roots[0]) + 1;
        $stripped = [];

        foreach ($entries as $path => $index) {
            $stripped[substr($path, $prefix)] = $index;
        }

        return $stripped;
    }

    /**
     * Extrai um arquivo contando os bytes de verdade: o tamanho declarado no
     * zip pode mentir.
     */
    private function extractEntry(ZipArchive $zip, int $index, string $target, int $bytesSoFar): int
    {
        $max = $this->maxBytes();
        $source = $zip->getStreamIndex($index);
        $buffer = tmpfile();
        $written = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    throw InvalidArtifact::invalidZip();
                }

                $written += strlen($chunk);

                if ($bytesSoFar + $written > $max) {
                    throw InvalidArtifact::tooLarge($max);
                }

                fwrite($buffer, $chunk);
            }

            rewind($buffer);
            $this->disk()->writeStream($target, $buffer);
        } finally {
            fclose($source);
            fclose($buffer);
        }

        return $written;
    }

    private function normalizePath(string $name): string
    {
        $path = str_replace('\\', '/', $name);

        if ($path === '' || strlen($path) > 255 || preg_match('/[\x00-\x1f]/', $path)
            || str_starts_with($path, '/') || preg_match('/^[a-z]:/i', $path)) {
            throw InvalidArtifact::unsafePath($name);
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $s) => $s !== '' && $s !== '.'));

        if ($segments === [] || in_array('..', $segments, true)) {
            throw InvalidArtifact::unsafePath($name);
        }

        return implode('/', $segments);
    }

    private function isIgnored(string $path): bool
    {
        return str_starts_with($path, '__MACOSX/') || in_array(basename($path), self::IGNORED, true);
    }

    private function isZip(string $path): bool
    {
        $handle = fopen($path, 'rb');
        $magic = fread($handle, 4);
        fclose($handle);

        return $magic === "PK\x03\x04" || $magic === "PK\x05\x06";
    }

    private function maxBytes(): int
    {
        return (int) config('ward.artifacts.max_bytes');
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('ward.artifacts.disk'));
    }
}
