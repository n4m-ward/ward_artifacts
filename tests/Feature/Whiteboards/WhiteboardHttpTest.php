<?php

namespace Tests\Feature\Whiteboards;

use App\Actions\Artifacts\CreateArtifactPreview;
use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\UpsertArtboard;
use App\Actions\Whiteboards\CreateWhiteboard;
use App\Actions\Whiteboards\WhiteboardEditors;
use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\User;
use App\Whiteboards\ElementFactory;
use App\Whiteboards\WhiteboardState;
use App\Whiteboards\WhiteboardStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WhiteboardHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $guest;

    private Artifact $board;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create(['username' => 'nam-ward']);
        $this->guest = User::factory()->create(['username' => 'ana']);
        $this->board = app(CreateWhiteboard::class)->handle($this->owner, 'Retro da sprint', 'Time');
    }

    /**
     * @return array<string, mixed>
     */
    private function element(string $id, int $version = 1): array
    {
        return app(ElementFactory::class)->base($id, 'rectangle', 0, 0, 100, 100, ['version' => $version, 'versionNonce' => 7, 'index' => 'a0', 'customData' => null]);
    }

    public function test_viewer_page_renders_with_config(): void
    {
        $this->actingAs($this->owner)->get($this->board->path())
            ->assertOk()
            ->assertSee('ward-whiteboard-config', false)
            ->assertSee('data-whiteboard', false)
            ->assertSee('· whiteboard', false);
    }

    public function test_owner_saves_elements_and_others_cannot(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('whiteboards.elements', $this->board->uuid), ['elements' => [$this->element('a')]])
            ->assertOk()->assertJson(['seq' => 1, 'saved' => ['a']]);

        // Outra pessoa, sem convite: nem vê o quadro (só eu).
        $this->actingAs($this->guest)
            ->postJson(route('whiteboards.elements', $this->board->uuid), ['elements' => [$this->element('b')]])
            ->assertForbidden();

        // Visível para usuários logados: vê, mas não edita.
        $this->board->update(['visibility' => Visibility::Users]);
        $this->actingAs($this->guest)->get($this->board->path())->assertOk();
        $this->actingAs($this->guest)
            ->postJson(route('whiteboards.elements', $this->board->uuid), ['elements' => [$this->element('b')]])
            ->assertForbidden();
    }

    public function test_invited_editor_sees_and_edits_even_when_private_until_removed(): void
    {
        app(WhiteboardEditors::class)->invite($this->board, $this->owner, '@ana');

        $this->actingAs($this->guest)->get($this->board->path())->assertOk();
        $this->actingAs($this->guest)
            ->postJson(route('whiteboards.elements', $this->board->uuid), ['elements' => [$this->element('b')]])
            ->assertOk();

        app(WhiteboardEditors::class)->remove($this->board, $this->guest->id);

        $this->actingAs($this->guest)
            ->postJson(route('whiteboards.elements', $this->board->uuid), ['elements' => [$this->element('c')]])
            ->assertForbidden();
    }

    public function test_share_modal_invites_and_removes_editors(): void
    {
        Volt::actingAs($this->owner)->test('artifacts.manage', ['artifact' => $this->board])
            ->set('invite', 'ninguem@example.com')
            ->call('inviteEditor')
            ->assertHasErrors('invite')
            ->set('invite', $this->guest->email)
            ->call('inviteEditor')
            ->assertHasNoErrors()
            ->assertSee('@ana');

        $this->assertTrue($this->board->isMember($this->guest));

        Volt::actingAs($this->owner)->test('artifacts.manage', ['artifact' => $this->board])
            ->call('removeEditor', $this->guest->id);

        $this->assertFalse($this->board->fresh()->isMember($this->guest));
    }

    public function test_live_returns_diff_presence_and_204(): void
    {
        $store = app(WhiteboardStore::class);
        $store->save($this->board, $this->owner, [$this->element('a')]);
        $live = route('whiteboards.live', $this->board->uuid);

        $full = $this->actingAs($this->owner)->getJson("{$live}?since=0")->assertOk()->json();
        $this->assertTrue($full['full']);
        $this->assertSame(['a'], array_column($full['elements'], 'id'));

        $this->actingAs($this->owner)->getJson("{$live}?since={$full['seq']}&p={$full['presence']}")->assertNoContent();

        // Outra pessoa entra no quadro: a resposta traz só a presença.
        $this->board->update(['visibility' => Visibility::Users]);
        $this->actingAs($this->guest)->postJson(route('whiteboards.presence', $this->board->uuid), ['pointer' => ['x' => 5, 'y' => 6], 'selected' => ['a']])->assertNoContent();
        $presence = $this->actingAs($this->owner)->getJson("{$live}?since={$full['seq']}&p={$full['presence']}")->assertOk()->json();
        $this->assertTrue($presence['unchanged']);
        $this->assertSame('ana', $presence['people'][0]['username']);
        $this->assertSame(['x' => 5, 'y' => 6], $presence['people'][0]['pointer']);

        $store->save($this->board, $this->owner, [$this->element('a', 2)]);
        $diff = $this->actingAs($this->owner)->getJson("{$live}?since={$full['seq']}")->assertOk()->json();
        $this->assertFalse($diff['full']);
        $this->assertSame(2, $diff['elements'][0]['version']);
    }

    public function test_live_without_session_needs_the_signed_url(): void
    {
        $this->getJson(route('whiteboards.live', $this->board->uuid))->assertForbidden();

        $signed = app(WhiteboardState::class)->liveUrl($this->board, now()->addMinutes(5));
        $this->getJson($signed.'&since=0')->assertOk()->assertJson(['comments' => null]);
    }

    public function test_preview_link_opens_read_only_board(): void
    {
        $url = app(CreateArtifactPreview::class)->handle($this->owner, $this->board);

        $this->get($url)->assertOk()->assertSee('"canEdit":false', false)->assertSee('"preview":true', false);
    }

    public function test_comments_votes_timer_and_versions(): void
    {
        app(WhiteboardStore::class)->save($this->board, $this->owner, [$this->element('a')]);
        $uuid = $this->board->uuid;
        $this->actingAs($this->owner);

        $comments = $this->postJson(route('whiteboards.comments.store', $uuid), ['body' => 'Rever este post-it', 'element' => 'a'])->assertOk()->json('comments');
        $this->assertSame('a', $comments[0]['element']);
        $this->postJson(route('whiteboards.comments.store', $uuid), ['body' => 'Feito', 'parent' => $comments[0]['id']])->assertOk();
        $this->patchJson(route('whiteboards.comments.update', [$uuid, $comments[0]['id']]), ['resolved' => true])->assertOk()->assertJsonPath('comments.0.resolved', true);

        $this->postJson(route('whiteboards.votes', $uuid), ['element' => 'a', 'delta' => 1])->assertUnprocessable();
        $this->postJson(route('whiteboards.voting', $uuid), ['action' => 'start', 'max' => 1])->assertOk();
        $this->postJson(route('whiteboards.votes', $uuid), ['element' => 'a', 'delta' => 1])->assertOk()->assertJsonPath('session.voting.totals.a', 1);
        $this->postJson(route('whiteboards.votes', $uuid), ['element' => 'a', 'delta' => 1])->assertUnprocessable();
        $this->postJson(route('whiteboards.voting', $uuid), ['action' => 'close'])->assertOk()->assertJsonPath('session.voting.open', false);

        $timer = $this->postJson(route('whiteboards.timer', $uuid), ['action' => 'start', 'seconds' => 60])->assertOk()->json('session.timer');
        $this->assertTrue($timer['running']);
        $this->postJson(route('whiteboards.timer', $uuid), ['action' => 'pause'])->assertOk()->assertJsonPath('session.timer.running', false);
        $this->postJson(route('whiteboards.timer', $uuid), ['action' => 'stop'])->assertOk()->assertJsonMissingPath('session.timer');

        $this->postJson(route('whiteboards.versions.store', $uuid), ['label' => 'antes'])->assertOk()->assertJsonPath('versions.0.number', 1);
        app(WhiteboardStore::class)->save($this->board, $this->owner, [$this->element('b')]);
        $this->postJson(route('whiteboards.versions.restore', [$uuid, 1]))->assertOk();
        $this->assertSame(['a'], array_column(app(WhiteboardStore::class)->all($this->board), 'id'));
    }

    public function test_files_upload_and_signed_download(): void
    {
        $png = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $this->actingAs($this->owner)->postJson(route('whiteboards.files.store', $this->board->uuid), ['id' => 'img1', 'dataURL' => $png])->assertOk();
        $this->actingAs($this->owner)->postJson(route('whiteboards.files.store', $this->board->uuid), ['id' => 'bad', 'dataURL' => 'data:text/html;base64,PGI+'])->assertUnprocessable();

        $live = $this->actingAs($this->owner)->getJson(route('whiteboards.live', $this->board->uuid).'?since=0')->json();
        $this->assertArrayHasKey('img1', $live['files']);

        auth()->logout();
        $this->get($live['files']['img1'])->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('whiteboards.files.show', [$this->board->uuid, 'img1']))->assertForbidden();
    }

    public function test_template_endpoint_and_artboard_embed(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('whiteboards.template', $this->board->uuid), ['name' => 'kanban', 'x' => 0, 'y' => 0])
            ->assertOk()->assertJsonCount(8, 'ids');

        $design = app(CreateDesign::class)->handle($this->owner, 'App', 'Time');
        app(UpsertArtboard::class)->handle($design, $this->owner, ['name' => 'Home', 'html' => '<p>oi</p>', 'w' => 390, 'h' => 844]);

        $list = $this->actingAs($this->owner)->getJson(route('whiteboards.artboards', $this->board->uuid))->assertOk()->json('designs');
        $this->assertSame('Home', $list[0]['boards'][0]['name']);

        $this->actingAs($this->owner)->get($list[0]['boards'][0]['embed'])->assertRedirect();
        $this->actingAs($this->guest)->get($list[0]['boards'][0]['embed'])->assertNotFound();
    }

    public function test_download_is_an_excalidraw_file(): void
    {
        app(WhiteboardStore::class)->save($this->board, $this->owner, [$this->element('a')]);

        $content = $this->actingAs($this->owner)->get(route('artifacts.download', $this->board))->assertOk()->streamedContent();
        $document = json_decode($content, true);
        $this->assertSame('excalidraw', $document['type']);
        $this->assertSame('a', $document['elements'][0]['id']);
    }

    public function test_whiteboards_page_lists_mine_and_shared_and_creates(): void
    {
        app(CreateWhiteboard::class)->handle($this->guest, 'Quadro da Ana', 'Time');
        $shared = Artifact::where('title', 'Quadro da Ana')->sole();
        app(WhiteboardEditors::class)->invite($shared, $this->guest, 'nam-ward');

        Volt::actingAs($this->owner)->test('whiteboards.index')
            ->assertSee('Retro da sprint')
            ->assertDontSee('Quadro da Ana')
            ->set('tab', 'shared')
            ->assertSee('Quadro da Ana')
            ->set('title', 'Fluxo de compra')
            ->set('project', 'Loja')
            ->set('template', 'flowchart')
            ->call('create')
            ->assertRedirect();

        $created = Artifact::where('title', 'Fluxo de compra')->sole();
        $this->assertTrue($created->isWhiteboard());
        $this->assertGreaterThan(5, $created->whiteboardElements()->count());

        $this->actingAs($this->owner)->get(route('whiteboards.index'))->assertOk()->assertSee('Whiteboards');
    }
}
