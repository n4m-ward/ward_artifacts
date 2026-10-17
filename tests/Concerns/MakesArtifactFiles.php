<?php

namespace Tests\Concerns;

use ZipArchive;

trait MakesArtifactFiles
{
    /** @var list<string> */
    private array $artifactTempFiles = [];

    protected function tearDownMakesArtifactFiles(): void
    {
        foreach ($this->artifactTempFiles as $path) {
            @unlink($path);
        }
    }

    protected function htmlFile(string $html = '<!doctype html><html><body><h1>Oi</h1></body></html>'): string
    {
        $path = $this->artifactTempPath();
        file_put_contents($path, $html);

        return $path;
    }

    /**
     * @param  array<string, string>  $files  caminho => conteúdo
     * @param  (callable(ZipArchive): void)|null  $tweak
     */
    protected function zipFile(array $files, ?callable $tweak = null): string
    {
        $path = $this->artifactTempPath();
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        if ($tweak) {
            $tweak($zip);
        }

        $zip->close();

        return $path;
    }

    private function artifactTempPath(): string
    {
        return $this->artifactTempFiles[] = tempnam(sys_get_temp_dir(), 'ward-test-');
    }
}
