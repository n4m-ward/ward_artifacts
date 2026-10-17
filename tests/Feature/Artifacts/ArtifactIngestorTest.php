<?php

namespace Tests\Feature\Artifacts;

use App\Artifacts\ArtifactIngestor;
use App\Artifacts\InvalidArtifact;
use App\Enums\ArtifactKind;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesArtifactFiles;
use Tests\TestCase;
use ZipArchive;

class ArtifactIngestorTest extends TestCase
{
    use MakesArtifactFiles;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
    }

    private function ingest(string $path)
    {
        return app(ArtifactIngestor::class)->ingest($path, 'abc/v1');
    }

    private function assertRejected(string $path, string $messageFragment): void
    {
        try {
            $this->ingest($path);
            $this->fail('O envio deveria ter sido recusado.');
        } catch (InvalidArtifact $e) {
            $this->assertStringContainsString($messageFragment, $e->getMessage());
        }

        Storage::disk('artifacts')->assertMissing('abc/v1');
    }

    public function test_single_html_is_stored_as_index(): void
    {
        $result = $this->ingest($this->htmlFile('<!doctype html><title>x</title><p>oi</p>'));

        $this->assertSame(ArtifactKind::Html, $result->kind);
        $this->assertSame(1, $result->fileCount);
        Storage::disk('artifacts')->assertExists('abc/v1/index.html');
        $this->assertSame('<!doctype html><title>x</title><p>oi</p>', Storage::disk('artifacts')->get('abc/v1/index.html'));
    }

    public function test_bundle_with_index_and_assets_is_extracted(): void
    {
        $result = $this->ingest($this->zipFile([
            'index.html' => '<!doctype html><script src="app.js"></script>',
            'app.js' => 'fetch("assets/tiles.json")',
            'assets/tiles.json' => '{"a":1}',
            'assets/mage.png' => "\x89PNG fake",
        ]));

        $this->assertSame(ArtifactKind::Bundle, $result->kind);
        $this->assertSame(4, $result->fileCount);
        foreach (['index.html', 'app.js', 'assets/tiles.json', 'assets/mage.png'] as $file) {
            Storage::disk('artifacts')->assertExists("abc/v1/{$file}");
        }
    }

    public function test_single_root_folder_is_stripped(): void
    {
        $this->ingest($this->zipFile([
            'vila/index.html' => '<p>oi</p>',
            'vila/assets/a.json' => '{}',
            '__MACOSX/vila/._index.html' => 'lixo',
            'vila/.DS_Store' => 'lixo',
        ]));

        Storage::disk('artifacts')->assertExists('abc/v1/index.html');
        Storage::disk('artifacts')->assertExists('abc/v1/assets/a.json');
        Storage::disk('artifacts')->assertMissing('abc/v1/.DS_Store');
    }

    public function test_rejects_empty_and_non_html(): void
    {
        $this->assertRejected($this->htmlFile(''), 'vazio');
        $this->assertRejected($this->htmlFile('só texto, nenhuma tag'), 'não parece ser HTML');
        $this->assertRejected($this->htmlFile("\x00\x01binario<p>"), 'não parece ser HTML');
    }

    public function test_rejects_files_over_the_limit(): void
    {
        config(['ward.artifacts.max_bytes' => 100]);

        $this->assertRejected($this->htmlFile('<p>'.str_repeat('a', 200).'</p>'), 'limite');
        $this->assertRejected($this->zipFile([
            'index.html' => '<p>oi</p>',
            'big.json' => str_repeat('a', 200),
        ]), 'limite');
    }

    public function test_rejects_bundle_without_index(): void
    {
        $this->assertRejected($this->zipFile(['page.html' => '<p>oi</p>']), 'index.html');
        $this->assertRejected($this->zipFile(['a/index.html' => '<p>', 'b/x.js' => '1']), 'index.html');
    }

    public function test_rejects_path_traversal(): void
    {
        $this->assertRejected($this->zipFile(['index.html' => '<p>', '../evil.js' => '1']), 'Caminho não permitido');
        $this->assertRejected($this->zipFile(['index.html' => '<p>', 'assets/../../evil.js' => '1']), 'Caminho não permitido');
        $this->assertRejected($this->zipFile(['index.html' => '<p>', '/etc/evil.js' => '1']), 'Caminho não permitido');
    }

    public function test_rejects_disallowed_extensions(): void
    {
        $this->assertRejected($this->zipFile(['index.html' => '<p>', 'shell.php' => '<?php']), 'não permitido');
        $this->assertRejected($this->zipFile(['index.html' => '<p>', '.htaccess' => 'x']), 'não permitido');
    }

    public function test_rejects_symlinks(): void
    {
        $path = $this->zipFile(['index.html' => '<p>', 'link.png' => '/etc/passwd'], function (ZipArchive $zip) {
            $zip->setExternalAttributesName('link.png', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });

        $this->assertRejected($path, 'link simbólico');
    }

    public function test_rejects_too_many_files(): void
    {
        config(['ward.artifacts.max_files' => 3]);

        $this->assertRejected($this->zipFile([
            'index.html' => '<p>', 'a.js' => '1', 'b.js' => '2', 'c.js' => '3',
        ]), 'mais de 3 arquivos');
    }

    public function test_rejects_corrupted_zip(): void
    {
        $this->assertRejected($this->htmlFile("PK\x03\x04isto não é um zip"), 'zip');
    }
}
