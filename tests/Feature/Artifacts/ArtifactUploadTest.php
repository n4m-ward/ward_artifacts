<?php

namespace Tests\Feature\Artifacts;

use App\Actions\Artifacts\CreateArtifactUpload;
use App\Actions\Artifacts\PublishArtifact;
use App\Enums\ArtifactKind;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesArtifactFiles;
use Tests\TestCase;

class ArtifactUploadTest extends TestCase
{
    use MakesArtifactFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
    }

    private function putRaw(string $url, string $body)
    {
        return $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $body);
    }

    public function test_raw_body_upload_creates_a_private_artifact(): void
    {
        $user = User::factory()->create(['username' => 'nam-ward']);
        $pending = app(CreateArtifactUpload::class)->forNew($user, 'Vila de Teste', 'Mapas');

        $response = $this->putRaw($pending->url, '<!doctype html><p>oi</p>');

        $response->assertCreated()
            ->assertJsonPath('artifact.title', 'Vila de Teste')
            ->assertJsonPath('artifact.visibility', 'owner')
            ->assertJsonPath('artifact.version', 1)
            ->assertJsonPath('artifact.kind', 'html');

        $artifact = Artifact::sole();
        $this->assertTrue($artifact->user->is($user));
        $this->assertStringEndsWith("/nam-ward/mapas/vila-de-teste-{$artifact->uuid}.html", $response->json('artifact.url'));
    }

    public function test_multipart_zip_upload_works(): void
    {
        $user = User::factory()->create();
        $pending = app(CreateArtifactUpload::class)->forNew($user, 'Jogo', 'Mapas');
        $zip = $this->zipFile(['index.html' => '<p>oi</p>', 'assets/a.json' => '{}']);

        $this->post($pending->url, ['file' => new UploadedFile($zip, 'jogo.zip', 'application/zip', null, true)])
            ->assertCreated()
            ->assertJsonPath('artifact.kind', ArtifactKind::Bundle->value);
    }

    public function test_link_is_single_use(): void
    {
        $pending = app(CreateArtifactUpload::class)->forNew(User::factory()->create(), 'X', 'Y');

        $this->putRaw($pending->url, '<p>1</p>')->assertCreated();
        $this->putRaw($pending->url, '<p>2</p>')->assertStatus(410);

        $this->assertSame(1, Artifact::count());
    }

    public function test_expired_link_is_rejected(): void
    {
        $pending = app(CreateArtifactUpload::class)->forNew(User::factory()->create(), 'X', 'Y');

        $this->travel(16)->minutes();

        $this->putRaw($pending->url, '<p>1</p>')->assertStatus(410);
    }

    public function test_unknown_token_is_rejected(): void
    {
        $this->putRaw('/uploads/'.str_repeat('a', 48), '<p>1</p>')->assertStatus(410);
    }

    public function test_rejected_upload_keeps_the_link_usable(): void
    {
        $pending = app(CreateArtifactUpload::class)->forNew(User::factory()->create(), 'X', 'Y');

        $this->putRaw($pending->url, 'sem tags')
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'HTML'));

        $this->putRaw($pending->url, '<p>agora sim</p>')->assertCreated();
    }

    public function test_oversized_body_is_rejected(): void
    {
        config(['ward.artifacts.max_bytes' => 50]);
        $pending = app(CreateArtifactUpload::class)->forNew(User::factory()->create(), 'X', 'Y');

        $this->putRaw($pending->url, '<p>'.str_repeat('a', 100).'</p>')->assertStatus(422);
        $this->assertSame(0, Artifact::count());
    }

    public function test_version_link_adds_a_version_to_the_same_artifact(): void
    {
        $user = User::factory()->create();
        $artifact = app(PublishArtifact::class)->handle($user, 'X', 'Y', $this->htmlFile());
        $pending = app(CreateArtifactUpload::class)->forVersion($user, $artifact);

        $this->putRaw($pending->url, '<p>v2</p>')
            ->assertOk()
            ->assertJsonPath('artifact.id', $artifact->uuid)
            ->assertJsonPath('artifact.version', 2);
    }

    public function test_version_link_checks_permission_at_upload_time(): void
    {
        $owner = User::factory()->admin()->create();
        $artifact = app(PublishArtifact::class)->handle($owner, 'X', 'Y', $this->htmlFile());
        $stranger = User::factory()->create();
        $pending = app(CreateArtifactUpload::class)->forVersion($stranger, $artifact);

        $this->putRaw($pending->url, '<p>v2</p>')->assertForbidden();
        $this->assertSame(1, $artifact->versions()->count());
    }
}
