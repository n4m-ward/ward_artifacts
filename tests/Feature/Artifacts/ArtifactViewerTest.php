<?php

namespace Tests\Feature\Artifacts;

use App\Actions\Artifacts\PublishArtifact;
use App\Actions\Artifacts\RenameArtifact;
use App\Actions\Artifacts\SetArtifactVisibility;
use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\ArtifactView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesArtifactFiles;
use Tests\TestCase;

class ArtifactViewerTest extends TestCase
{
    use MakesArtifactFiles, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create(['username' => 'nam-ward']);
    }

    private function publish(?Visibility $visibility = null, ?int $publicHours = null): Artifact
    {
        $artifact = app(PublishArtifact::class)->handle($this->owner, 'Vila de Teste', 'Mapas', $this->htmlFile());

        if ($visibility) {
            app(SetArtifactVisibility::class)->base($artifact, $visibility);
        }

        if ($publicHours) {
            app(SetArtifactVisibility::class)->makePublic($artifact, $publicHours);
        }

        return $artifact->fresh(['user', 'project', 'currentVersion']);
    }

    public function test_owner_sees_the_frame_with_the_content_iframe(): void
    {
        $artifact = $this->publish();

        $this->actingAs($this->owner)
            ->get($artifact->path())
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Vila de Teste')
            ->assertSee('Só eu')
            ->assertSee('http://content.localhost/c/v', false)
            ->assertSee('allow-same-origin', false)
            ->assertSee('rel="alternate"', false);

        $this->assertSame(0, ArtifactView::count());
    }

    public function test_owner_only_artifact_is_hidden_from_other_users(): void
    {
        $artifact = $this->publish();

        $this->actingAs(User::factory()->create())->get($artifact->path())->assertNotFound();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $artifact = $this->publish(Visibility::Users);

        $this->get($artifact->path())->assertRedirect(route('login'));
    }

    public function test_logged_in_users_can_open_and_the_view_is_recorded(): void
    {
        $artifact = $this->publish(Visibility::Users);
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->get($artifact->path())->assertOk()->assertSee('Usuários logados');
        $first = ArtifactView::sole();

        $this->travel(1)->hour();
        $this->actingAs($viewer)->get($artifact->path())->assertOk();

        $view = ArtifactView::sole();
        $this->assertTrue($view->first_viewed_at->equalTo($first->first_viewed_at));
        $this->assertTrue($view->last_viewed_at->isAfter($first->last_viewed_at));
        $this->assertTrue($viewer->viewedArtifacts->first()->is($artifact));
    }

    public function test_public_artifact_opens_for_guests_until_it_expires(): void
    {
        $artifact = $this->publish(Visibility::Owner, 2);

        $this->get($artifact->path())->assertOk()->assertSee('Público até');

        $this->travel(3)->hours();
        $this->get($artifact->path())->assertRedirect(route('login'));
    }

    public function test_admin_sees_any_artifact(): void
    {
        $artifact = $this->publish();

        $this->actingAs(User::factory()->admin()->create())
            ->get($artifact->path())
            ->assertOk()
            ->assertSee('Só @nam-ward');
    }

    public function test_outdated_urls_redirect_to_the_current_one(): void
    {
        $artifact = $this->publish();
        $oldPath = $artifact->path();

        app(RenameArtifact::class)->handle($artifact, 'Mapa Novo');
        $artifact->refresh();

        $this->actingAs($this->owner)->get($oldPath)->assertRedirect($artifact->path())->assertStatus(301);
        $this->actingAs($this->owner)->get("/outro/projeto/qualquer-{$artifact->uuid}.html")->assertStatus(301);
        $this->actingAs($this->owner)->get("/nam-ward/mapas/{$artifact->uuid}.html")->assertStatus(301);
    }

    public function test_redirect_does_not_leak_the_path_to_unauthorized_users(): void
    {
        $artifact = $this->publish();

        $this->actingAs(User::factory()->create())
            ->get("/x/y/z-{$artifact->uuid}.html")
            ->assertNotFound();
    }

    public function test_unknown_uuid_is_not_found(): void
    {
        $this->actingAs($this->owner)
            ->get('/nam-ward/mapas/nada-'.str_repeat('a', 32).'.html')
            ->assertNotFound();
    }
}
