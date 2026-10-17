<?php

namespace Tests\Feature\Artifacts;

use App\Actions\Artifacts\CreateArtifactPreview;
use App\Actions\Artifacts\PublishArtifact;
use App\Actions\Artifacts\SetArtifactVisibility;
use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\ArtifactPreview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesArtifactFiles;
use Tests\TestCase;

class ArtifactPreviewTest extends TestCase
{
    use MakesArtifactFiles, RefreshDatabase;

    private User $owner;

    private Artifact $artifact;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create(['username' => 'nam-ward']);
        $this->artifact = app(PublishArtifact::class)->handle($this->owner, 'Vila', 'Mapas', $this->htmlFile());
    }

    private function previewUrl(): string
    {
        return app(CreateArtifactPreview::class)->handle($this->owner, $this->artifact);
    }

    public function test_first_visit_renders_without_login_and_burns_the_token(): void
    {
        $url = $this->previewUrl();

        $this->get($url)
            ->assertOk()
            ->assertSee('Pré-visualização')
            ->assertSee('http://content.localhost/c/v', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->get($url)->assertForbidden();
        $this->assertNotNull(ArtifactPreview::sole()->used_at);
    }

    public function test_preview_does_not_cache_and_content_link_is_short_lived(): void
    {
        $response = $this->get($this->previewUrl())->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        preg_match('#/c/v\d+\.(\d+)\.#', $response->getContent(), $match);
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, (int) $match[1], 5);
    }

    public function test_logged_in_user_who_can_see_goes_to_the_normal_page_without_burning_it(): void
    {
        $url = $this->previewUrl();

        $this->actingAs($this->owner)->get($url)->assertRedirect($this->artifact->path());
        $this->assertNull(ArtifactPreview::sole()->used_at);

        // O token continua valendo para o navegador da IA.
        auth()->logout();
        $this->get($url)->assertOk();
    }

    public function test_after_use_only_logged_in_users_with_permission_pass(): void
    {
        $url = $this->previewUrl();
        $this->get($url)->assertOk();

        $this->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs($this->owner)->get($url)->assertRedirect($this->artifact->path());
        $this->actingAs(User::factory()->admin()->create())->get($url)->assertRedirect($this->artifact->path());

        app(SetArtifactVisibility::class)->base($this->artifact, Visibility::Users);
        $this->actingAs(User::factory()->create())->get($url)->assertRedirect($this->artifact->path());
    }

    public function test_expired_token_is_forbidden_for_guests(): void
    {
        $url = $this->previewUrl();

        $this->travel(61)->minutes();

        $this->get($url)->assertForbidden();
        $this->actingAs($this->owner)->get($url)->assertRedirect($this->artifact->path());
    }

    public function test_head_requests_do_not_burn_the_token(): void
    {
        $url = $this->previewUrl();

        $this->call('HEAD', $url)->assertOk();
        $this->assertNull(ArtifactPreview::sole()->used_at);

        $this->get($url)->assertOk();
    }

    public function test_unknown_token_is_not_found(): void
    {
        $this->get('/p/'.str_repeat('a', 48))->assertNotFound();
    }

    public function test_preview_dies_with_the_artifact(): void
    {
        $url = $this->previewUrl();

        $this->artifact->delete();

        $this->get($url)->assertNotFound();
        $this->assertSame(0, ArtifactPreview::count());
    }
}
