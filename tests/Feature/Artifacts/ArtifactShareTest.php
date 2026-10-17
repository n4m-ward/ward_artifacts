<?php

namespace Tests\Feature\Artifacts;

use App\Actions\Artifacts\PublishArtifact;
use App\Actions\Artifacts\SetArtifactVisibility;
use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\MakesArtifactFiles;
use Tests\TestCase;

class ArtifactShareTest extends TestCase
{
    use MakesArtifactFiles, RefreshDatabase;

    private User $owner;

    private Artifact $artifact;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create();
        $this->artifact = app(PublishArtifact::class)->handle($this->owner, 'Vila', 'Mapas', $this->htmlFile());
    }

    public function test_viewer_shows_share_controls_only_to_managers(): void
    {
        $this->actingAs($this->owner)->get($this->artifact->path())->assertSee('Compartilhar');

        app(SetArtifactVisibility::class)->base($this->artifact, Visibility::Users);
        $this->actingAs(User::factory()->create())->get($this->artifact->path())
            ->assertOk()
            ->assertDontSee('Compartilhar');
    }

    public function test_owner_changes_who_can_see(): void
    {
        Volt::actingAs($this->owner)->test('artifacts.manage', ['artifact' => $this->artifact])
            ->set('visibility', 'users')
            ->assertHasNoErrors()
            ->assertSee('Usuários logados');

        $this->assertSame(Visibility::Users, $this->artifact->fresh()->visibility);
    }

    public function test_owner_makes_it_public_for_a_limited_time_and_ends_it(): void
    {
        $component = Volt::actingAs($this->owner)->test('artifacts.manage', ['artifact' => $this->artifact])
            ->set('publicHours', '6')
            ->call('makePublic')
            ->assertHasNoErrors()
            ->assertSee('Público até');

        $this->assertEqualsWithDelta(now()->addHours(6)->timestamp, $this->artifact->fresh()->public_until->timestamp, 5);

        $component->call('endPublic');
        $this->assertNull($this->artifact->fresh()->public_until);
    }

    public function test_public_duration_must_be_one_of_the_allowed_options(): void
    {
        Volt::actingAs($this->owner)->test('artifacts.manage', ['artifact' => $this->artifact])
            ->set('publicHours', '720')
            ->call('makePublic')
            ->assertHasErrors('publicHours');

        $this->assertNull($this->artifact->fresh()->public_until);
    }

    public function test_others_cannot_change_visibility(): void
    {
        app(SetArtifactVisibility::class)->base($this->artifact, Visibility::Users);
        $other = User::factory()->create();

        Volt::actingAs($other)->test('artifacts.manage', ['artifact' => $this->artifact])
            ->set('publicHours', '6')
            ->call('makePublic')
            ->assertForbidden();

        Volt::actingAs($other)->test('artifacts.manage', ['artifact' => $this->artifact])
            ->set('visibility', 'owner')
            ->assertForbidden();

        $this->assertNull($this->artifact->fresh()->public_until);
        $this->assertSame(Visibility::Users, $this->artifact->fresh()->visibility);
    }

    public function test_admin_can_manage_others_artifacts(): void
    {
        Volt::actingAs(User::factory()->admin()->create())->test('artifacts.manage', ['artifact' => $this->artifact])
            ->set('publicHours', '1')
            ->call('makePublic')
            ->assertHasNoErrors();

        $this->assertTrue($this->artifact->fresh()->isPublic());
    }

    public function test_rename_redirects_to_the_new_url(): void
    {
        Volt::actingAs($this->owner)->test('artifacts.manage', ['artifact' => $this->artifact])
            ->set('title', 'Vila Nova')
            ->call('rename')
            ->assertRedirect($this->artifact->fresh()->path());

        $this->assertStringContainsString('/vila-nova-', $this->artifact->fresh()->path());
    }
}
