<?php

namespace Tests\Feature\Whiteboards;

use App\Actions\Whiteboards\WhiteboardEditors;
use App\Enums\Visibility;
use App\Mcp\Apps\WhiteboardApp;
use App\Mcp\Servers\WardServer;
use App\Mcp\Tools\AddElementsTool;
use App\Mcp\Tools\ArrangeElementsTool;
use App\Mcp\Tools\CreateDiagramTool;
use App\Mcp\Tools\CreateWhiteboardTool;
use App\Mcp\Tools\DeleteElementsTool;
use App\Mcp\Tools\GetDesignTool;
use App\Mcp\Tools\GetWhiteboardTool;
use App\Mcp\Tools\OpenWhiteboardTool;
use App\Mcp\Tools\ResolveCommentTool;
use App\Mcp\Tools\SaveDesignVersionTool;
use App\Mcp\Tools\ShowInCanvasTool;
use App\Mcp\Tools\UpdateElementsTool;
use App\Mcp\Tools\WhiteboardLiveTool;
use App\Models\Artifact;
use App\Models\User;
use App\Whiteboards\WhiteboardComments;
use App\Whiteboards\WhiteboardPresence;
use App\Whiteboards\WhiteboardStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Request as McpRequest;
use Tests\TestCase;

class WhiteboardToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->user = User::factory()->create(['username' => 'nam-ward']);
    }

    private function board(?string $template = null): Artifact
    {
        WardServer::actingAs($this->user)->tool(CreateWhiteboardTool::class, array_filter(['title' => 'Fluxo', 'project' => 'Loja', 'template' => $template]))->assertOk();

        return Artifact::where('type', 'whiteboard')->latest('id')->first();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function elements(Artifact $board): array
    {
        return array_column(app(WhiteboardStore::class)->all($board), null, 'id');
    }

    public function test_create_and_read(): void
    {
        WardServer::actingAs($this->user)->tool(CreateWhiteboardTool::class, ['title' => 'Retro', 'project' => 'Time', 'template' => 'retro'])
            ->assertOk()
            ->assertSee('/nam-ward/time/retro-')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('type', 'whiteboard')->where('visibility', 'owner')->etc());

        $board = Artifact::sole();

        WardServer::actingAs($this->user)->tool(GetWhiteboardTool::class, ['whiteboard' => $board->uuid])
            ->assertOk()
            ->assertSee('O que foi bem')
            ->assertSee('"type":"sticky"')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 6)->has('frames', 3)->etc());

        // Ferramentas de design recusam o quadro com uma dica.
        WardServer::actingAs($this->user)->tool(GetDesignTool::class, ['design' => $board->uuid])->assertHasErrors(['whiteboard']);
    }

    public function test_add_elements_with_bound_arrow_label_and_frame(): void
    {
        $board = $this->board();

        WardServer::actingAs($this->user)->tool(AddElementsTool::class, [
            'whiteboard' => $board->uuid,
            'elements' => [
                ['id' => 'a', 'type' => 'sticky', 'text' => 'Ideia', 'color' => 'pink'],
                ['id' => 'b', 'type' => 'diamond', 'text' => 'Pago?'],
                ['id' => 'ab', 'type' => 'arrow', 'from' => 'a', 'to' => 'b', 'label' => 'sim'],
                ['id' => 'f', 'type' => 'frame', 'name' => 'Seção', 'children' => ['a', 'b']],
            ],
        ])->assertOk()->assertSee('a, b, ab, f');

        $elements = $this->elements($board);
        $this->assertSame('rectangle', $elements['a']['type']);
        $this->assertSame('sticky', $elements['a']['customData']['wardKind']);
        $this->assertSame('#fcc2d7', $elements['a']['backgroundColor']);
        $this->assertSame('a', $elements['a-text']['containerId']);
        $this->assertSame(['elementId' => 'a', 'focus' => 0, 'gap' => 8], $elements['ab']['startBinding']);
        $this->assertSame('b', $elements['ab']['endBinding']['elementId']);
        $this->assertContains(['type' => 'arrow', 'id' => 'ab'], $elements['b']['boundElements']);
        $this->assertSame('sim', $elements['ab-label']['text']);
        $this->assertSame('f', $elements['a']['frameId']);
        $this->assertSame('f', $elements['a-text']['frameId']);
        $this->assertTrue($elements['a']['customData']['wardPending']);

        // A seta sai da borda do post-it em direção ao losango (não do centro).
        $this->assertGreaterThan($elements['a']['x'] + $elements['a']['width'], $elements['ab']['x']);

        // A IA aparece para quem está vendo.
        $this->assertSame('editing', app(WhiteboardPresence::class)->ai($board)['action']);
    }

    public function test_add_elements_errors_are_actionable(): void
    {
        $board = $this->board();

        WardServer::actingAs($this->user)->tool(AddElementsTool::class, [
            'whiteboard' => $board->uuid,
            'elements' => [['type' => 'arrow', 'from' => 'nope', 'to' => 'nada']],
        ])->assertHasErrors(['"nope" não existe']);

        WardServer::actingAs($this->user)->tool(AddElementsTool::class, [
            'whiteboard' => $board->uuid,
            'elements' => [['type' => 'hexagon']],
        ])->assertHasErrors(['hexagon']);
    }

    public function test_place_right_of_inside_and_grid(): void
    {
        $board = $this->board();

        WardServer::actingAs($this->user)->tool(AddElementsTool::class, [
            'whiteboard' => $board->uuid,
            'elements' => [['id' => 'base', 'type' => 'rectangle', 'text' => 'Base', 'x' => 0, 'y' => 0, 'width' => 200, 'height' => 100], ['id' => 'fr', 'type' => 'frame', 'name' => 'Caixa', 'x' => 0, 'y' => 400, 'width' => 300, 'height' => 200]],
        ])->assertOk();

        WardServer::actingAs($this->user)->tool(AddElementsTool::class, [
            'whiteboard' => $board->uuid,
            'elements' => [['id' => 'r', 'type' => 'sticky', 'text' => 'ao lado']],
            'place' => ['right_of' => 'base'],
        ])->assertOk();

        WardServer::actingAs($this->user)->tool(AddElementsTool::class, [
            'whiteboard' => $board->uuid,
            'elements' => [['id' => 'i1', 'type' => 'sticky'], ['id' => 'i2', 'type' => 'sticky'], ['id' => 'i3', 'type' => 'sticky']],
            'place' => ['inside' => 'fr'],
        ])->assertOk();

        $elements = $this->elements($board);
        $this->assertSame(320.0, (float) $elements['r']['x']);
        $this->assertSame('fr', $elements['i1']['frameId']);
        // O frame cresceu para conter os três post-its lado a lado.
        $this->assertGreaterThan(300, $elements['fr']['width']);
    }

    public function test_update_moves_text_and_reroutes_arrows(): void
    {
        $board = $this->board();
        WardServer::actingAs($this->user)->tool(AddElementsTool::class, [
            'whiteboard' => $board->uuid,
            'elements' => [
                ['id' => 'a', 'type' => 'rectangle', 'text' => 'A', 'x' => 0, 'y' => 0],
                ['id' => 'b', 'type' => 'rectangle', 'text' => 'B', 'x' => 400, 'y' => 0],
                ['id' => 'ab', 'type' => 'arrow', 'from' => 'a', 'to' => 'b'],
            ],
        ])->assertOk();
        $before = $this->elements($board);

        WardServer::actingAs($this->user)->tool(UpdateElementsTool::class, [
            'whiteboard' => $board->uuid,
            'changes' => [['id' => 'b', 'dy' => 300, 'text' => 'B2', 'color' => 'green'], ['id' => 'ab', 'label' => 'depois']],
        ])->assertOk();

        $after = $this->elements($board);
        $this->assertSame($before['b']['y'] + 300, $after['b']['y']);
        $this->assertSame($before['b-text']['y'] + 300, $after['b-text']['y']);
        $this->assertSame('B2', $after['b-text']['originalText']);
        $this->assertSame('#b2f2bb', $after['b']['backgroundColor']);
        $this->assertGreaterThan(200, end($after['ab']['points'])[1]);
        $this->assertSame('depois', $after['ab-text']['text']);
        $this->assertGreaterThan($before['b']['version'], $after['b']['version']);
    }

    public function test_delete_frame_takes_children_and_unbinds_arrows(): void
    {
        $board = $this->board();
        WardServer::actingAs($this->user)->tool(AddElementsTool::class, [
            'whiteboard' => $board->uuid,
            'elements' => [
                ['id' => 'a', 'type' => 'sticky', 'text' => 'A', 'x' => 0, 'y' => 0],
                ['id' => 'b', 'type' => 'sticky', 'text' => 'B', 'x' => 600, 'y' => 0],
                ['id' => 'ab', 'type' => 'arrow', 'from' => 'a', 'to' => 'b'],
                ['id' => 'f', 'type' => 'frame', 'children' => ['a']],
            ],
        ])->assertOk();

        WardServer::actingAs($this->user)->tool(DeleteElementsTool::class, ['whiteboard' => $board->uuid, 'ids' => ['f']])->assertOk();

        $elements = $this->elements($board);
        $this->assertArrayNotHasKey('a', $elements);
        $this->assertArrayNotHasKey('a-text', $elements);
        $this->assertNull($elements['ab']['startBinding']);
        $this->assertSame('b', $elements['ab']['endBinding']['elementId']);
    }

    public function test_diagram_layouts(): void
    {
        $board = $this->board();
        $nodes = [['id' => 'start', 'text' => 'Início', 'shape' => 'ellipse'], ['id' => 'pay', 'text' => 'Pagar'], ['id' => 'ok', 'text' => 'Pago?', 'shape' => 'diamond'], ['id' => 'end', 'text' => 'Fim']];
        $edges = [['from' => 'start', 'to' => 'pay'], ['from' => 'pay', 'to' => 'ok'], ['from' => 'ok', 'to' => 'end', 'label' => 'sim'], ['from' => 'ok', 'to' => 'pay', 'label' => 'não']];

        WardServer::actingAs($this->user)->tool(CreateDiagramTool::class, [
            'whiteboard' => $board->uuid, 'kind' => 'flowchart', 'nodes' => $nodes, 'edges' => $edges, 'frame' => 'Pagamento',
        ])->assertOk()->assertSee('flowchart');

        $elements = $this->elements($board);
        // De cima para baixo, sem sobrepor, e o ciclo (não → Pagar) não quebra o layout.
        $this->assertLessThan($elements['pay']['y'], $elements['start']['y']);
        $this->assertLessThan($elements['ok']['y'], $elements['pay']['y'] + $elements['pay']['height']);
        $this->assertLessThan($elements['end']['y'], $elements['ok']['y'] + $elements['ok']['height']);
        $frame = collect($elements)->firstWhere('type', 'frame');
        $this->assertSame('Pagamento', $frame['name']);
        $this->assertSame($frame['id'], $elements['pay']['frameId']);

        WardServer::actingAs($this->user)->tool(CreateDiagramTool::class, [
            'whiteboard' => $board->uuid, 'kind' => 'mindmap',
            'nodes' => [['id' => 'root', 'text' => 'Produto'], ['id' => 'm1', 'text' => 'Preço'], ['id' => 'm2', 'text' => 'Canal'], ['id' => 'm3', 'text' => 'Público']],
            'edges' => [['from' => 'root', 'to' => 'm1'], ['from' => 'root', 'to' => 'm2'], ['from' => 'root', 'to' => 'm3']],
        ])->assertOk();

        $elements = $this->elements($board);
        $root = $elements['root'];
        // Ramos dos dois lados da raiz.
        $this->assertGreaterThan($root['x'] + $root['width'], $elements['m1']['x']);
        $this->assertLessThan($root['x'], $elements['m3']['x'] + $elements['m3']['width']);
        $this->assertNull($elements['root-m1-0']['endArrowhead']);
    }

    public function test_arrange_cluster_groups_into_frames(): void
    {
        $board = $this->board();
        WardServer::actingAs($this->user)->tool(AddElementsTool::class, [
            'whiteboard' => $board->uuid,
            'elements' => array_map(fn ($i) => ['id' => "n{$i}", 'type' => 'sticky', 'text' => "Nota {$i}"], range(1, 5)),
        ])->assertOk();

        WardServer::actingAs($this->user)->tool(ArrangeElementsTool::class, [
            'whiteboard' => $board->uuid,
            'mode' => 'cluster',
            'groups' => [['name' => 'Processo', 'ids' => ['n1', 'n2', 'n3']], ['name' => 'Ferramentas', 'ids' => ['n4', 'n5']]],
        ])->assertOk()->assertSee('Frames');

        $elements = $this->elements($board);
        $frames = collect($elements)->where('type', 'frame')->keyBy('name');
        $this->assertSame($frames['Processo']['id'], $elements['n2']['frameId']);
        $this->assertSame($frames['Ferramentas']['id'], $elements['n5']['frameId']);
        $this->assertGreaterThan($frames['Processo']['x'] + $frames['Processo']['width'], $frames['Ferramentas']['x']);
    }

    public function test_show_in_canvas_versions_and_comments_on_a_whiteboard(): void
    {
        $board = $this->board('kanban');
        $sticky = collect($this->elements($board))->first(fn ($element) => ($element['customData']['wardKind'] ?? null) === 'sticky');

        WardServer::actingAs($this->user)->tool(ShowInCanvasTool::class, ['design' => $board->uuid, 'action' => 'point', 'element' => $sticky['id'], 'message' => 'Esta tarefa'])
            ->assertOk()->assertSee('Cursor apontando');
        $ai = app(WhiteboardPresence::class)->ai($board);
        $this->assertSame('point', $ai['action']);
        $this->assertSame([$sticky['id']], $ai['elements']);

        WardServer::actingAs($this->user)->tool(ShowInCanvasTool::class, ['design' => $board->uuid, 'action' => 'play'])->assertHasErrors(['focus, fit e point']);

        WardServer::actingAs($this->user)->tool(SaveDesignVersionTool::class, ['design' => $board->uuid, 'label' => 'inicial'])->assertOk()->assertSee('Versão 1 do quadro');

        $thread = app(WhiteboardComments::class)->open($board, $this->user, ['body' => 'Mudar a cor', 'element' => $sticky['id']]);
        WardServer::actingAs($this->user)->tool(GetWhiteboardTool::class, ['whiteboard' => $board->uuid])->assertSee('Mudar a cor');
        WardServer::actingAs($this->user)->tool(ResolveCommentTool::class, ['design' => $board->uuid, 'comment' => $thread->number, 'message' => 'Cor trocada'])
            ->assertOk()->assertSee('resolvido');
        $this->assertNotNull($thread->fresh()->resolved_at);
    }

    public function test_permissions_for_tools(): void
    {
        $board = $this->board();
        $other = User::factory()->create(['username' => 'ana']);

        WardServer::actingAs($other)->tool(GetWhiteboardTool::class, ['whiteboard' => $board->uuid])->assertHasErrors(['não encontrado']);

        $board->update(['visibility' => Visibility::Users]);
        WardServer::actingAs($other)->tool(GetWhiteboardTool::class, ['whiteboard' => $board->uuid])->assertOk();
        WardServer::actingAs($other)->tool(AddElementsTool::class, ['whiteboard' => $board->uuid, 'elements' => [['type' => 'sticky']]])->assertHasErrors(['convidado editor']);

        app(WhiteboardEditors::class)->invite($board, $this->user, 'ana');
        WardServer::actingAs($other)->tool(AddElementsTool::class, ['whiteboard' => $board->uuid, 'elements' => [['type' => 'sticky', 'text' => 'da Ana']]])->assertOk();
    }

    public function test_open_whiteboard_and_app_live(): void
    {
        $board = $this->board('brainstorm');

        WardServer::actingAs($this->user)->tool(OpenWhiteboardTool::class, ['whiteboard' => $board->uuid])
            ->assertOk()
            ->assertSee('aberto no chat')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('whiteboard', $board->uuid)
                ->where('app.app', true)
                ->where('app.canEdit', false)
                ->has('app.liveUrl')
                ->has('app.initial.elements', 7)
                ->etc());

        $live = WardServer::actingAs($this->user)->tool(WhiteboardLiveTool::class, ['whiteboard' => $board->uuid, 'since' => 0]);
        $live->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json->where('changed', true)->where('full', true)->etc());

        $seq = $board->fresh()->whiteboard_seq;
        WardServer::actingAs($this->user)->tool(WhiteboardLiveTool::class, ['whiteboard' => $board->uuid, 'since' => $seq, 'p' => app(WhiteboardPresence::class)->signature($board)])
            ->assertOk()->assertStructuredContent(['changed' => false]);
    }

    public function test_whiteboard_app_resource_loads_the_board_bundle(): void
    {
        $html = app(WhiteboardApp::class)->handle(new McpRequest([]))->content()->__toString();
        $this->assertStringContainsString('WardWhiteboard.mount', $html);
        $this->assertStringContainsString('whiteboard_live', $html);
        $this->assertStringStartsWith('ui://ward-artifacts/whiteboard-', app(WhiteboardApp::class)->uri());
    }
}
