<?php

namespace Tests\Feature\Whiteboards;

use App\Actions\Whiteboards\CreateWhiteboard;
use App\Models\Artifact;
use App\Models\User;
use App\Whiteboards\ElementFactory;
use App\Whiteboards\InvalidWhiteboard;
use App\Whiteboards\WhiteboardStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhiteboardStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Artifact $board;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create(['username' => 'nam-ward']);
        $this->board = app(CreateWhiteboard::class)->handle($this->owner, 'Retro', 'Time');
    }

    /**
     * @return array<string, mixed>
     */
    private function element(string $id, int $version = 1, int $nonce = 100, array $extra = []): array
    {
        return app(ElementFactory::class)->base($id, 'rectangle', 10, 20, 100, 50, $extra + ['version' => $version, 'versionNonce' => $nonce, 'index' => 'a0', 'customData' => null]);
    }

    public function test_save_bumps_seq_and_keeps_the_newest_version(): void
    {
        $store = app(WhiteboardStore::class);

        $first = $store->save($this->board, $this->owner, [$this->element('a', 2)]);
        $this->assertSame(1, $first['seq']);
        $this->assertSame(['a'], $first['saved']);

        // Versão mais velha chegando depois: é recusada e o guardado volta como stale.
        $stale = $store->save($this->board, $this->owner, [$this->element('a', 1)]);
        $this->assertSame([], $stale['saved']);
        $this->assertSame(2, $stale['stale'][0]['version']);
        $this->assertSame(1, $this->board->fresh()->whiteboard_seq);

        // Empate de versão: vence o versionNonce menor (regra do Excalidraw).
        $tie = $store->save($this->board, $this->owner, [$this->element('a', 2, 50, ['x' => 999])]);
        $this->assertSame(['a'], $tie['saved']);
        $this->assertSame(999, $store->find($this->board, 'a')['x']);

        // Mesma versão e mesmo nonce: nada muda, nada volta.
        $same = $store->save($this->board, $this->owner, [$this->element('a', 2, 50, ['x' => 999])]);
        $this->assertSame([], $same['saved']);
        $this->assertSame([], $same['stale']);
    }

    public function test_since_returns_only_newer_elements_including_deleted(): void
    {
        $store = app(WhiteboardStore::class);
        $store->save($this->board, $this->owner, [$this->element('a'), $this->element('b')]);
        $store->save($this->board, $this->owner, [$this->element('b', 2, 1, ['isDeleted' => true])]);

        $since = $store->since($this->board->fresh(), 1);
        $this->assertCount(1, $since);
        $this->assertTrue($since[0]['isDeleted']);
        $this->assertSame(['a'], array_column($store->all($this->board), 'id'));
    }

    public function test_all_is_ordered_by_fractional_index_byte_by_byte(): void
    {
        $store = app(WhiteboardStore::class);
        $store->save($this->board, $this->owner, [
            $this->element('upper', 1, 1, ['index' => 'aZ']),
            $this->element('lower', 1, 1, ['index' => 'aa']),
            $this->element('none', 1, 1, ['index' => null]),
            $this->element('first', 1, 1, ['index' => 'a0']),
        ]);

        $this->assertSame(['first', 'upper', 'lower', 'none'], array_column($store->all($this->board), 'id'));
    }

    public function test_sanitizer_rejects_unknown_types_and_strips_bad_links(): void
    {
        $store = app(WhiteboardStore::class);

        try {
            $store->save($this->board, $this->owner, [['id' => 'x', 'type' => 'script', 'version' => 1, 'versionNonce' => 1]]);
            $this->fail('Tipo desconhecido passou.');
        } catch (InvalidWhiteboard $e) {
            $this->assertStringContainsString('não suportado', $e->getMessage());
        }

        $store->save($this->board, $this->owner, [
            $this->element('l', 1, 1, ['link' => 'javascript:alert(1)']),
            app(ElementFactory::class)->base('e', 'embeddable', 0, 0, 100, 100, ['link' => 'https://evil.example/x']),
            app(ElementFactory::class)->base('y', 'embeddable', 0, 0, 100, 100, ['link' => 'https://www.youtube.com/watch?v=1']),
        ]);

        $this->assertNull($store->find($this->board, 'l')['link']);
        $this->assertNull($store->find($this->board, 'e')['link']);
        $this->assertSame('https://www.youtube.com/watch?v=1', $store->find($this->board, 'y')['link']);
    }

    public function test_element_limit(): void
    {
        config(['ward.whiteboards.max_elements' => 2]);

        $this->expectException(InvalidWhiteboard::class);
        app(WhiteboardStore::class)->save($this->board, $this->owner, [$this->element('a'), $this->element('b'), $this->element('c')]);
    }

    public function test_purge_removes_old_deleted_and_forces_full_sync_for_late_clients(): void
    {
        $store = app(WhiteboardStore::class);
        $store->save($this->board, $this->owner, [$this->element('a')]);
        $store->save($this->board, $this->owner, [$this->element('a', 2, 1, ['isDeleted' => true])]);

        $this->travel(8)->days();
        $board = $this->board->fresh();
        $this->assertSame(1, $store->purge($board));
        $this->assertTrue($store->needsFull($board->fresh(), 1));
        $this->assertFalse($store->needsFull($board->fresh(), 2));
    }
}
