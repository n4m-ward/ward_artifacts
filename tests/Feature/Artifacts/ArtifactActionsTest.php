<?php

namespace Tests\Feature\Artifacts;

use App\Actions\Artifacts\DeleteArtifact;
use App\Actions\Artifacts\PublishArtifact;
use App\Actions\Artifacts\RenameArtifact;
use App\Actions\Artifacts\SetArtifactVisibility;
use App\Actions\Artifacts\StoreArtifactVersion;
use App\Artifacts\InvalidArtifact;
use App\Enums\ArtifactKind;
use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\MakesArtifactFiles;
use Tests\TestCase;

class ArtifactActionsTest extends TestCase
{
    use MakesArtifactFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
    }

    private function publish(User $user, string $title = 'Vila de Teste', string $project = 'Sistema de Mapas'): Artifact
    {
        return app(PublishArtifact::class)->handle($user, $title, $project, $this->htmlFile());
    }

    public function test_publish_creates_a_private_artifact_with_its_first_version(): void
    {
        $user = User::factory()->create(['username' => 'nam-ward']);

        $artifact = $this->publish($user);

        $this->assertSame(Visibility::Owner, $artifact->visibility);
        $this->assertNull($artifact->public_until);
        $this->assertSame(32, strlen($artifact->uuid));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $artifact->uuid);
        $this->assertSame("/nam-ward/sistema-de-mapas/vila-de-teste-{$artifact->uuid}.html", $artifact->path());

        $version = $artifact->currentVersion;
        $this->assertSame(1, $version->number);
        $this->assertSame(ArtifactKind::Html, $version->kind);
        Storage::disk('artifacts')->assertExists($version->filePath());
    }

    public function test_same_project_name_reuses_the_project(): void
    {
        $user = User::factory()->create();

        $a = $this->publish($user, 'A', 'Sistema de Mapas');
        $b = $this->publish($user, 'B', 'sistema de mapas');

        $this->assertTrue($a->project->is($b->project));
        $this->assertSame(1, $user->projects()->count());
    }

    public function test_invalid_upload_creates_nothing(): void
    {
        $user = User::factory()->create();

        try {
            app(PublishArtifact::class)->handle($user, 'X', 'Y', $this->htmlFile('sem tags'));
            $this->fail('Deveria ter recusado.');
        } catch (InvalidArtifact) {
        }

        $this->assertSame(0, Artifact::count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_new_version_keeps_the_url_and_moves_the_pointer(): void
    {
        $user = User::factory()->create();
        $artifact = $this->publish($user);
        $path = $artifact->path();

        $version = app(StoreArtifactVersion::class)->handle(
            $artifact, $user, $this->zipFile(['index.html' => '<p>v2</p>', 'a.png' => 'x']),
        );

        $artifact->refresh();
        $this->assertSame(2, $version->number);
        $this->assertTrue($artifact->currentVersion->is($version));
        $this->assertSame(ArtifactKind::Bundle, $version->kind);
        $this->assertSame($path, $artifact->path());
    }

    public function test_old_versions_are_pruned(): void
    {
        config(['ward.artifacts.keep_versions' => 2]);
        $user = User::factory()->create();
        $artifact = $this->publish($user);
        $first = $artifact->currentVersion;

        app(StoreArtifactVersion::class)->handle($artifact, $user, $this->htmlFile('<p>2</p>'));
        app(StoreArtifactVersion::class)->handle($artifact, $user, $this->htmlFile('<p>3</p>'));

        $this->assertSame([2, 3], $artifact->versions()->orderBy('number')->pluck('number')->all());
        Storage::disk('artifacts')->assertMissing($first->storage_path);
    }

    public function test_rename_changes_title_and_slug_but_not_uuid(): void
    {
        $artifact = $this->publish(User::factory()->create());
        $uuid = $artifact->uuid;

        app(RenameArtifact::class)->handle($artifact, '  Mapa   Novo  ');

        $this->assertSame('Mapa Novo', $artifact->title);
        $this->assertSame('mapa-novo', $artifact->slug);
        $this->assertSame($uuid, $artifact->uuid);
    }

    public function test_public_visibility_is_always_temporary(): void
    {
        $artifact = $this->publish(User::factory()->create());
        $action = app(SetArtifactVisibility::class);

        $action->handle($artifact, Visibility::Users, 48);
        $this->assertTrue($artifact->isPublic());
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $artifact->public_until->timestamp, 5);

        $this->travel(49)->hours();
        $this->assertFalse($artifact->fresh()->isPublic());
        $this->assertSame(Visibility::Users, $artifact->fresh()->visibility);

        $this->expectException(InvalidArgumentException::class);
        $action->handle($artifact, Visibility::Owner, 24 * 30);
    }

    public function test_every_allowed_public_duration_is_accepted(): void
    {
        $this->assertSame([1, 2, 6, 12, 24, 48, 168], SetArtifactVisibility::publicDurations());
    }

    public function test_delete_removes_files(): void
    {
        $artifact = $this->publish(User::factory()->create());

        app(DeleteArtifact::class)->handle($artifact);

        $this->assertModelMissing($artifact);
        Storage::disk('artifacts')->assertMissing($artifact->uuid);
    }

    public function test_deleting_a_user_removes_their_artifact_files(): void
    {
        $user = User::factory()->create();
        $artifact = $this->publish($user);

        $user->delete();

        $this->assertModelMissing($artifact);
        Storage::disk('artifacts')->assertMissing($artifact->uuid);
    }
}
