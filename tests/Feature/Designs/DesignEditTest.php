<?php

namespace Tests\Feature\Designs;

use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\UpdateDesignLayout;
use App\Actions\Designs\UpsertArtboard;
use App\Models\Artifact;
use App\Models\DesignBoard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DesignEditTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Artifact $design;

    private DesignBoard $home;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create(['username' => 'nam-ward']);
        $this->design = app(CreateDesign::class)->handle($this->owner, 'App mobile', 'Onboarding');
        $this->home = app(UpsertArtboard::class)->handle($this->design, $this->owner, [
            'name' => 'Home', 'html' => '<!doctype html><p>inicio</p>', 'w' => 390, 'h' => 844,
        ]);
        app(UpdateDesignLayout::class)->handle($this->design, [], [
            ['key' => 'fluxo', 'kind' => 'title', 'text' => 'Fluxo', 'x' => 0, 'y' => -200],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $ops
     */
    private function edit(array $ops, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->postJson(route('designs.edit', $this->design->uuid), ['ops' => $ops]);
    }

    public function test_moves_resizes_and_renames_artboards(): void
    {
        $revision = $this->design->fresh()->design_revision;

        $this->edit([['op' => 'board.update', 'id' => $this->home->id, 'x' => 120.4, 'y' => -40, 'w' => 430, 'h' => 900, 'title' => 'Início']])
            ->assertOk()
            ->assertJsonPath('state.boards.0.x', 120)
            ->assertJsonPath('state.boards.0.w', 430)
            ->assertJsonPath('state.boards.0.title', 'Início')
            ->assertJsonPath('state.revision', $revision + 1);

        $this->assertSame([120, -40, 430, 900], [$this->home->fresh()->x, $this->home->fresh()->y, $this->home->fresh()->w, $this->home->fresh()->h]);
    }

    public function test_duplicate_copies_the_html_next_to_the_original(): void
    {
        $response = $this->edit([['op' => 'board.duplicate', 'id' => $this->home->id]])->assertOk();

        $copy = $this->design->boards()->where('name', 'Home 2')->sole();
        $this->assertSame($copy->id, $response->json('results.0.id'));
        $this->assertSame(390 + 80, $copy->x);
        $this->assertStringContainsString('inicio', Storage::disk('artifacts')->get($copy->storage_path));
    }

    public function test_delete_returns_what_is_needed_to_undo_it(): void
    {
        $removed = $this->edit([['op' => 'board.delete', 'id' => $this->home->id]])
            ->assertOk()
            ->assertJsonCount(0, 'state.boards')
            ->json('results.0.removed');

        $this->assertSame('Home', $removed['name']);
        $this->assertStringContainsString('inicio', $removed['html']);

        $this->edit([['op' => 'board.create', ...$removed]])
            ->assertOk()
            ->assertJsonPath('state.boards.0.name', 'Home')
            ->assertJsonPath('state.boards.0.w', 390);
    }

    public function test_notes_can_be_created_styled_and_deleted(): void
    {
        $this->edit([
            ['op' => 'note.upsert', 'key' => 'n1', 'kind' => 'sticky', 'x' => 10, 'y' => 900, 'w' => 260, 'text' => 'Revisar', 'color' => 'teal'],
            ['op' => 'note.upsert', 'key' => 'n1', 'size' => 'l', 'bold' => true],
        ])->assertOk();

        $note = $this->design->notes()->where('key', 'n1')->sole();
        $this->assertSame(['teal', 'l', true, 'Revisar'], [$note->color, $note->size, $note->bold, $note->text]);

        $this->edit([['op' => 'note.delete', 'key' => 'n1']])
            ->assertOk()
            ->assertJsonPath('results.0.removed.text', 'Revisar');
        $this->assertFalse($this->design->notes()->where('key', 'n1')->exists());
    }

    public function test_invalid_changes_are_refused_with_the_current_state(): void
    {
        app(UpsertArtboard::class)->handle($this->design, $this->owner, [
            'name' => 'Checkout', 'html' => '<!doctype html><p>pagar</p>', 'w' => 390, 'h' => 844,
        ]);

        $this->edit([['op' => 'board.update', 'id' => $this->home->id, 'name' => 'Checkout']])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Já existe um artboard chamado "Checkout".')
            ->assertJsonCount(2, 'state.boards');

        $this->edit([['op' => 'note.upsert', 'key' => 'n1', 'text' => 'x', 'color' => 'black']])->assertUnprocessable();
        $this->edit([['op' => 'board.update', 'id' => 999999, 'x' => 1]])->assertUnprocessable();
        $this->edit([['op' => 'board.hack', 'id' => $this->home->id]])->assertUnprocessable();
    }

    public function test_only_the_owner_or_an_admin_can_edit(): void
    {
        $this->postJson(route('designs.edit', $this->design->uuid), ['ops' => [['op' => 'note.delete', 'key' => 'fluxo']]])->assertUnauthorized();
        $this->edit([['op' => 'board.update', 'id' => $this->home->id, 'x' => 5]], User::factory()->create())->assertForbidden();

        $this->edit([['op' => 'board.update', 'id' => $this->home->id, 'x' => 5]], User::factory()->admin()->create())->assertOk();
    }

    public function test_source_endpoint_returns_the_board_html(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('designs.board-source', [$this->design->uuid, $this->home->id]))
            ->assertOk()
            ->assertJsonPath('revision', $this->home->revision)
            ->assertJsonPath('html', '<!doctype html><p>inicio</p>');

        $this->actingAs(User::factory()->create())
            ->getJson(route('designs.board-source', [$this->design->uuid, $this->home->id]))
            ->assertForbidden();
    }

    public function test_page_only_loads_the_editor_for_people_who_can_edit(): void
    {
        $config = fn (User $user) => json_decode(
            preg_replace('#.*<script type="application/json" id="ward-design-config">(.*?)</script>.*#s', '$1', $this->actingAs($user)->get($this->design->path())->getContent()),
            true,
        );

        $this->assertSame(route('designs.edit', $this->design->uuid), $config($this->owner)['edit']['url']);

        $this->design->update(['visibility' => 'users']);
        $this->assertNull($config(User::factory()->create())['edit']);
    }
}
