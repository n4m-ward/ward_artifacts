<?php

namespace Tests\Feature\Artifacts;

use App\Actions\Artifacts\PublishArtifact;
use App\Actions\Artifacts\RecordArtifactView;
use App\Actions\Artifacts\SetArtifactVisibility;
use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\MakesArtifactFiles;
use Tests\TestCase;

class ArtifactPanelTest extends TestCase
{
    use MakesArtifactFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
    }

    private function publish(User $owner, string $title, ?Visibility $visibility = null): Artifact
    {
        $artifact = app(PublishArtifact::class)->handle($owner, $title, 'Projeto', $this->htmlFile());

        if ($visibility) {
            app(SetArtifactVisibility::class)->base($artifact, $visibility);
        }

        return $artifact;
    }

    public function test_mine_lists_only_my_artifacts(): void
    {
        $me = User::factory()->create();
        $this->publish($me, 'Meu mapa');
        $this->publish(User::factory()->create(), 'Mapa alheio', Visibility::Users);

        Volt::actingAs($me)->test('artifacts.index')
            ->assertSee('Meu mapa')
            ->assertDontSee('Mapa alheio');
    }

    public function test_viewed_lists_others_artifacts_i_opened_and_can_still_see(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $opened = $this->publish($other, 'Aberto por mim', Visibility::Users);
        $closed = $this->publish($other, 'Fechado depois', Visibility::Users);
        $this->publish($other, 'Nunca abri', Visibility::Users);

        app(RecordArtifactView::class)->handle($me, $opened);
        app(RecordArtifactView::class)->handle($me, $closed);
        app(SetArtifactVisibility::class)->base($closed, Visibility::Owner);

        Volt::actingAs($me)->test('artifacts.index', ['tab' => 'viewed'])
            ->set('tab', 'viewed')
            ->assertSee('Aberto por mim')
            ->assertDontSee('Fechado depois')
            ->assertDontSee('Nunca abri');
    }

    public function test_all_tab_is_admin_only(): void
    {
        $player = User::factory()->create();
        $this->publish(User::factory()->create(), 'Privado de outro');

        Volt::actingAs($player)->test('artifacts.index')
            ->call('setTab', 'all')
            ->assertSet('tab', 'mine')
            ->assertDontSee('Privado de outro')
            ->assertDontSee('Todos');
    }

    public function test_admin_sees_everything_and_filters_by_owner(): void
    {
        $admin = User::factory()->admin()->create();
        $this->publish(User::factory()->create(['username' => 'ana']), 'Mapa da Ana');
        $this->publish(User::factory()->create(['username' => 'bia']), 'Mapa da Bia');

        Volt::actingAs($admin)->test('artifacts.index')
            ->call('setTab', 'all')
            ->assertSee('Mapa da Ana')
            ->assertSee('Mapa da Bia')
            ->set('owner', 'ana')
            ->assertSee('Mapa da Ana')
            ->assertDontSee('Mapa da Bia');
    }

    public function test_search_filters_by_title(): void
    {
        $me = User::factory()->create();
        $this->publish($me, 'Mapa da vila');
        $this->publish($me, 'Tela de login');

        Volt::actingAs($me)->test('artifacts.index')
            ->set('search', 'vila')
            ->assertSee('Mapa da vila')
            ->assertDontSee('Tela de login');
    }

    public function test_owner_renames_and_deletes_from_the_panel(): void
    {
        $me = User::factory()->create();
        $artifact = $this->publish($me, 'Antigo');

        Volt::actingAs($me)->test('artifacts.index')
            ->call('startRename', $artifact->uuid)
            ->set('renameTitle', 'Novo nome')
            ->call('rename')
            ->assertHasNoErrors();
        $this->assertSame('Novo nome', $artifact->fresh()->title);

        Volt::actingAs($me)->test('artifacts.index')
            ->call('confirmDelete', $artifact->uuid)
            ->call('delete');
        $this->assertModelMissing($artifact);
    }

    public function test_player_cannot_rename_or_delete_others(): void
    {
        $artifact = $this->publish(User::factory()->create(), 'Alheio', Visibility::Users);
        $player = User::factory()->create();

        Volt::actingAs($player)->test('artifacts.index')->call('startRename', $artifact->uuid)->assertForbidden();
        Volt::actingAs($player)->test('artifacts.index')->call('confirmDelete', $artifact->uuid)->assertForbidden();
        Volt::actingAs($player)->test('artifacts.index')
            ->set('deleting', $artifact->uuid)
            ->call('delete')
            ->assertForbidden();

        $this->assertModelExists($artifact);
    }

    public function test_admin_can_delete_others(): void
    {
        $artifact = $this->publish(User::factory()->create(), 'Alheio');

        Volt::actingAs(User::factory()->admin()->create())->test('artifacts.index')
            ->call('confirmDelete', $artifact->uuid)
            ->call('delete');

        $this->assertModelMissing($artifact);
    }
}
