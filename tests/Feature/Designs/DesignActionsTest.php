<?php

namespace Tests\Feature\Designs;

use App\Actions\Artifacts\DeleteArtifact;
use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\DeleteArtboard;
use App\Actions\Designs\SaveDesignSnapshot;
use App\Actions\Designs\UpdateDesignLayout;
use App\Actions\Designs\UpsertArtboard;
use App\Designs\InvalidDesign;
use App\Enums\ArtifactType;
use App\Enums\BoardKind;
use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DesignActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Artifact $design;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create(['username' => 'nam-ward']);
        $this->design = app(CreateDesign::class)->handle($this->owner, 'App mobile', 'Onboarding v2');
    }

    private function upsert(array $data)
    {
        return app(UpsertArtboard::class)->handle($this->design, $this->owner, $data);
    }

    private function html(string $text = 'oi'): string
    {
        return "<!doctype html><title>{$text}</title><p>{$text}</p>";
    }

    public function test_create_design_is_a_private_design_artifact(): void
    {
        $this->assertSame(ArtifactType::Design, $this->design->type);
        $this->assertTrue($this->design->isDesign());
        $this->assertSame(Visibility::Owner, $this->design->visibility);
        $this->assertNull($this->design->current_version_id);
        $this->assertSame(0, $this->design->design_revision);
        $this->assertSame("/nam-ward/onboarding-v2/app-mobile-{$this->design->uuid}.html", $this->design->path());
    }

    public function test_new_artboards_are_stored_and_placed_in_a_row(): void
    {
        $first = $this->upsert(['name' => 'Home', 'html' => $this->html(), 'w' => 390, 'h' => 844]);
        $second = $this->upsert(['name' => 'Checkout', 'html' => $this->html(), 'w' => 390, 'h' => 844, 'kind' => 'fixed']);

        $this->assertSame([0, 0], [$first->x, $first->y]);
        $this->assertSame([470, 0], [$second->x, $second->y]);
        $this->assertSame(1, $first->revision);
        $this->assertSame(BoardKind::Fixed, $second->kind);
        Storage::disk('artifacts')->assertExists($first->storage_path);

        $this->design->refresh();
        $this->assertSame(2, $this->design->design_revision);
        $this->assertSame($first->size_bytes + $second->size_bytes, $this->design->size_bytes);
    }

    public function test_updating_html_creates_a_new_revision_and_drops_the_old_file(): void
    {
        $board = $this->upsert(['name' => 'Home', 'html' => $this->html('v1'), 'w' => 390, 'h' => 844]);
        $oldPath = $board->storage_path;

        $board = $this->upsert(['name' => 'Home', 'html' => $this->html('v2')]);

        $this->assertSame(2, $board->revision);
        Storage::disk('artifacts')->assertMissing($oldPath);
        $this->assertStringContainsString('v2', Storage::disk('artifacts')->get($board->storage_path));
    }

    public function test_moving_without_html_keeps_the_revision(): void
    {
        $this->upsert(['name' => 'Home', 'html' => $this->html(), 'w' => 390, 'h' => 844]);

        $board = $this->upsert(['name' => 'Home', 'x' => 1000, 'y' => 500, 'title' => 'Início']);

        $this->assertSame([1000, 500, 1], [$board->x, $board->y, $board->revision]);
        $this->assertSame('Início', $board->title);
    }

    public function test_invalid_artboards_are_rejected(): void
    {
        $cases = [
            ['name' => '../etc', 'html' => $this->html(), 'w' => 100, 'h' => 100],
            ['name' => 'Home', 'w' => 100, 'h' => 100],
            ['name' => 'Home', 'html' => $this->html()],
            ['name' => 'Home', 'html' => 'sem tags', 'w' => 100, 'h' => 100],
            ['name' => 'Home', 'html' => $this->html(), 'w' => 10, 'h' => 100],
            ['name' => 'Home', 'html' => $this->html(), 'w' => 100, 'h' => 100, 'kind' => 'poster'],
        ];

        foreach ($cases as $case) {
            try {
                $this->upsert($case);
                $this->fail('Deveria ter recusado: '.json_encode($case));
            } catch (InvalidDesign) {
            }
        }

        $this->assertSame(0, $this->design->boards()->count());
        $this->assertSame(0, $this->design->fresh()->design_revision);
    }

    public function test_board_limits(): void
    {
        config(['ward.designs.max_board_bytes' => 50, 'ward.designs.max_boards' => 1]);

        try {
            $this->upsert(['name' => 'Big', 'html' => '<p>'.str_repeat('a', 100).'</p>', 'w' => 100, 'h' => 100]);
            $this->fail('Deveria recusar HTML grande.');
        } catch (InvalidDesign) {
        }

        $this->upsert(['name' => 'A', 'html' => '<p>a</p>', 'w' => 100, 'h' => 100]);

        $this->expectException(InvalidDesign::class);
        $this->upsert(['name' => 'B', 'html' => '<p>b</p>', 'w' => 100, 'h' => 100]);
    }

    public function test_layout_moves_renames_and_manages_notes(): void
    {
        $this->upsert(['name' => 'Home', 'html' => $this->html(), 'w' => 390, 'h' => 844]);

        app(UpdateDesignLayout::class)->handle($this->design,
            [['name' => 'Home', 'new_name' => 'Inicio', 'x' => 200, 'interactive' => true]],
            [
                ['key' => 'fluxo', 'kind' => 'title', 'text' => 'Fluxo de cadastro', 'x' => 0, 'y' => -220],
                ['key' => 'obs', 'text' => 'Revisar cores', 'color' => 'pink'],
            ],
        );

        $board = $this->design->boards()->sole();
        $this->assertSame(['Inicio', 200, true], [$board->name, $board->x, $board->interactive]);
        $this->assertSame(2, $this->design->notes()->count());
        $this->assertSame('sticky', $this->design->notes()->where('key', 'obs')->value('kind'));

        app(UpdateDesignLayout::class)->handle($this->design, [], [['key' => 'obs', 'delete' => true]]);
        $this->assertSame(1, $this->design->notes()->count());
    }

    public function test_layout_validation(): void
    {
        $this->upsert(['name' => 'Home', 'html' => $this->html(), 'w' => 390, 'h' => 844]);
        $layout = app(UpdateDesignLayout::class);

        foreach ([
            [[['name' => 'Nada', 'x' => 1]], []],
            [[['name' => 'Home', 'w' => 9000]], []],
            [[], [['key' => 'a b', 'text' => 'x']]],
            [[], [['key' => 'a', 'text' => 'x', 'color' => 'neon']]],
            [[], [['key' => 'nova']]],
        ] as [$boards, $notes]) {
            try {
                $layout->handle($this->design, $boards, $notes);
                $this->fail('Deveria ter recusado.');
            } catch (InvalidDesign) {
            }
        }

        $this->assertSame(1, $this->design->fresh()->design_revision);
    }

    public function test_delete_artboard_removes_its_files(): void
    {
        $board = $this->upsert(['name' => 'Home', 'html' => $this->html(), 'w' => 390, 'h' => 844]);

        app(DeleteArtboard::class)->handle($this->design, 'Home');

        $this->assertModelMissing($board);
        Storage::disk('artifacts')->assertMissing($board->storage_path);
    }

    public function test_snapshots_hold_the_whole_canvas_and_rotate(): void
    {
        config(['ward.designs.keep_snapshots' => 2]);
        $this->upsert(['name' => 'Home', 'html' => $this->html('home'), 'w' => 390, 'h' => 844]);
        $this->upsert(['name' => 'Post', 'html' => $this->html('post'), 'w' => 1080, 'h' => 1080]);

        $first = app(SaveDesignSnapshot::class)->handle($this->design, $this->owner, 'Primeira rodada');
        $this->assertSame([1, 2, 'Primeira rodada'], [$first->number, $first->board_count, $first->label]);

        $zipPath = tempnam(sys_get_temp_dir(), 'snap');
        file_put_contents($zipPath, Storage::disk('artifacts')->get($first->storage_path));
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $canvas = json_decode($zip->getFromName('canvas.json'), true);
        $this->assertSame(['Home', 'Post'], array_column($canvas['boards'], 'name'));
        $this->assertStringContainsString('post', $zip->getFromName('boards/Post.html'));
        $zip->close();
        unlink($zipPath);

        app(SaveDesignSnapshot::class)->handle($this->design, $this->owner);
        app(SaveDesignSnapshot::class)->handle($this->design, $this->owner);

        $this->assertSame([2, 3], $this->design->snapshots()->orderBy('number')->pluck('number')->all());
        Storage::disk('artifacts')->assertMissing($first->storage_path);
    }

    public function test_empty_design_cannot_be_snapshotted(): void
    {
        $this->expectException(InvalidDesign::class);

        app(SaveDesignSnapshot::class)->handle($this->design, $this->owner);
    }

    public function test_deleting_the_design_removes_everything(): void
    {
        $board = $this->upsert(['name' => 'Home', 'html' => $this->html(), 'w' => 390, 'h' => 844]);

        app(DeleteArtifact::class)->handle($this->design);

        $this->assertModelMissing($board);
        Storage::disk('artifacts')->assertMissing($this->design->uuid);
    }

    public function test_design_actions_refuse_pages(): void
    {
        $page = Artifact::factory()->for($this->owner)->create();

        $this->expectException(InvalidDesign::class);
        app(UpsertArtboard::class)->handle($page, $this->owner, ['name' => 'X', 'html' => $this->html(), 'w' => 100, 'h' => 100]);
    }
}
