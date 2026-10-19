<?php

namespace Tests\Feature\Mcp;

use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\UpsertArtboard;
use App\Designs\DesignPresence;
use App\Designs\DesignState;
use App\Mcp\Servers\WardServer;
use App\Mcp\Tools\DesignRenderPreviewTool;
use App\Mcp\Tools\DesignReportViewTool;
use App\Mcp\Tools\ShowInCanvasTool;
use App\Mcp\Tools\UpsertArtboardTool;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * A IA conduz quem está vendo o canvas (show_in_canvas): presença no ao vivo,
 * resultado devolvido pelo canvas e a resposta da ferramenta sem ninguém vendo.
 */
class DesignPresenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Artifact $design;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        config(['ward.designs.presence_wait_seconds' => 0.3]);
        $this->user = User::factory()->create(['username' => 'dono']);
        $this->design = app(CreateDesign::class)->handle($this->user, 'Loja', 'App');
        $upsert = app(UpsertArtboard::class);
        $upsert->handle($this->design, $this->user, ['name' => 'Home', 'w' => 390, 'h' => 844, 'html' => '<!doctype html><body><button data-ward-to="Checkout">Comprar agora</button><p>Olá</p></body>']);
        $upsert->handle($this->design, $this->user, ['name' => 'Checkout', 'w' => 390, 'h' => 844, 'html' => '<!doctype html><body><button id="voltar" data-ward-back>Voltar</button></body>']);
    }

    private function show(array $data)
    {
        return WardServer::actingAs($this->user)->tool(ShowInCanvasTool::class, ['design' => $this->design->uuid, ...$data]);
    }

    public function test_click_without_viewers_answers_from_the_html(): void
    {
        $this->show(['action' => 'click', 'board' => 'Home', 'element' => 'comprar', 'message' => 'Testando a compra'])
            ->assertSee('Pelo HTML, ele leva a "Checkout"')
            ->assertSee('Ninguém está com o canvas aberto')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('watching', false)->where('element.tag', 'button')->etc());

        $this->show(['action' => 'point', 'board' => 'Checkout', 'element' => '#voltar'])->assertSee('volta à tela anterior');
        $this->show(['action' => 'click', 'board' => 'Home', 'element' => 'Não existe'])->assertHasErrors(['Não achei "Não existe"']);
        $this->show(['action' => 'focus'])->assertHasErrors(['precisa de board']);

        $presence = app(DesignPresence::class)->current($this->design);
        $this->assertSame(['action' => 'point', 'board' => 'Checkout', 'element' => '#voltar'], array_intersect_key($presence, array_flip(['action', 'board', 'element'])));
    }

    public function test_live_endpoint_sends_presence_and_viewer_results_reach_the_tool(): void
    {
        $state = app(DesignState::class);
        $live = $state->liveUrl($this->design, now()->addHour());
        $revision = $this->design->fresh()->design_revision;

        // Sem p (cliente antigo) e nada mudou: 204.
        $this->get($live.'&rev='.$revision)->assertNoContent();

        $this->show(['action' => 'focus', 'board' => 'Home', 'message' => 'Olhando a Home']);
        $seq = app(DesignPresence::class)->current($this->design)['seq'];

        $this->get($live.'&rev='.$revision.'&p=0')->assertOk()
            ->assertJsonPath('unchanged', true)->assertJsonPath('presence.board', 'Home')->assertJsonPath('presence.message', 'Olhando a Home');
        $this->get($live.'&rev='.$revision.'&p='.$seq)->assertNoContent();
        $this->assertTrue(app(DesignPresence::class)->hasViewers($this->design));

        // O canvas devolve o resultado de uma ação pela URL assinada.
        $sent = app(DesignPresence::class)->push($this->design, 'Claude', ['action' => 'click', 'board' => 'Home', 'element' => 'Comprar']);
        $this->call('POST', $state->presenceUrl($this->design, now()->addHour()), [], [], [], ['CONTENT_TYPE' => 'text/plain'],
            json_encode(['seq' => $sent['seq'], 'result' => ['found' => true, 'screen' => 'Checkout', 'overlays' => ['Inexistente'], 'errors' => []]]))
            ->assertNoContent();

        $this->assertSame(['found' => true, 'screen' => 'Checkout', 'navigated' => false],
            app(DesignPresence::class)->await($this->design, $sent['seq'], 0.1));

        // Sem assinatura, nada.
        $this->call('POST', route('designs.presence', $this->design->uuid), [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{}')->assertForbidden();
    }

    public function test_app_fallback_reports_results_and_tools_move_the_camera(): void
    {
        $sent = app(DesignPresence::class)->push($this->design, 'Claude', ['action' => 'play', 'board' => 'Home']);

        WardServer::actingAs($this->user)->tool(DesignReportViewTool::class, [
            'design' => $this->design->uuid, 'board' => 'Home', 'presence_seq' => $sent['seq'], 'presence_result' => ['found' => true, 'screen' => 'Home'],
        ])->assertOk();
        $this->assertSame('Home', app(DesignPresence::class)->await($this->design, $sent['seq'], 0.1)['screen']);

        WardServer::actingAs($this->user)->tool(UpsertArtboardTool::class, ['design' => $this->design->uuid, 'name' => 'Fim', 'w' => 390, 'h' => 844, 'html' => '<!doctype html><p>Fim</p>']);
        $this->assertSame(['editing', 'Fim'], [app(DesignPresence::class)->current($this->design)['action'], app(DesignPresence::class)->current($this->design)['board']]);
    }

    public function test_stream_preview_gets_tokens_components_and_assets_base(): void
    {
        $this->design->forceFill(['design_tokens' => '--paper: #101010;'])->save();
        app(UpsertArtboard::class)->handle($this->design, $this->user, ['name' => 'Navbar', 'w' => 390, 'h' => 60, 'html' => '<!doctype html><body><nav class="topo">Menu do app</nav></body>']);

        WardServer::actingAs($this->user)->tool(DesignRenderPreviewTool::class, [
            'design' => $this->design->uuid, 'name' => 'Nova', 'html' => '<!doctype html><html><head><title>x</title></head><body><ward-include name="Navbar"></ward-include><img src="assets/logo.png">',
        ])->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('html', fn ($html) => str_contains($html, '--paper: #101010;') && str_contains($html, 'Menu do app') && str_contains($html, '<base href="'))
            ->etc());
    }
}
