<?php

namespace Tests\Feature\Artifacts;

use App\Actions\Artifacts\PublishArtifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesArtifactFiles;
use Tests\TestCase;
use ZipArchive;

class ArtifactDownloadTest extends TestCase
{
    use MakesArtifactFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
    }

    public function test_owner_downloads_a_single_html(): void
    {
        $owner = User::factory()->create();
        $artifact = app(PublishArtifact::class)->handle($owner, 'Vila de Teste', 'Mapas', $this->htmlFile('<p>oi</p>'));

        $response = $this->actingAs($owner)->get(route('artifacts.download', $artifact));

        $response->assertOk()->assertDownload('vila-de-teste.html');
        $this->assertSame('<p>oi</p>', $response->streamedContent());
    }

    public function test_bundle_downloads_as_zip_with_relative_paths(): void
    {
        $owner = User::factory()->create();
        $artifact = app(PublishArtifact::class)->handle($owner, 'Jogo', 'Mapas', $this->zipFile([
            'index.html' => '<p>oi</p>',
            'assets/a.json' => '{}',
        ]));

        $response = $this->actingAs($owner)->get(route('artifacts.download', $artifact));
        $response->assertOk()->assertDownload('jogo.zip');

        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);
        $this->assertSame(['assets/a.json', 'index.html'], $names);
    }

    public function test_download_follows_view_permission(): void
    {
        $artifact = app(PublishArtifact::class)->handle(User::factory()->create(), 'X', 'Y', $this->htmlFile());

        $this->actingAs(User::factory()->create())->get(route('artifacts.download', $artifact))->assertForbidden();
    }
}
