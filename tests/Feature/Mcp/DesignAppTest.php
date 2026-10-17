<?php

namespace Tests\Feature\Mcp;

use App\Actions\Artifacts\PublishArtifact;
use App\Actions\Artifacts\SetArtifactVisibility;
use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\UpsertArtboard;
use App\Artifacts\ContentGateway;
use App\Enums\Visibility;
use App\Mcp\Apps\DesignApp;
use App\Mcp\Servers\WardServer;
use App\Mcp\Tools\DesignLiveTool;
use App\Mcp\Tools\DesignReportViewTool;
use App\Mcp\Tools\GetDesignTool;
use App\Mcp\Tools\OpenDesignTool;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Passport\Passport;
use Mockery;
use Tests\Concerns\MakesArtifactFiles;
use Tests\TestCase;

class DesignAppTest extends TestCase
{
    use MakesArtifactFiles, RefreshDatabase;

    private User $owner;

    private Artifact $design;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create(['username' => 'nam-ward']);
        $this->design = app(CreateDesign::class)->handle($this->owner, 'App mobile', 'Onboarding');
        app(UpsertArtboard::class)->handle($this->design, $this->owner, [
            'name' => 'Home', 'html' => '<!doctype html><p>inicio</p>', 'w' => 390, 'h' => 844,
        ]);
    }

    private function rpc(string $method, array $params = [], array $headers = [])
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params,
        ], ['Accept' => 'application/json, text/event-stream'] + $headers);
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function listTools()
    {
        $tools = collect();
        $cursor = null;

        do {
            $page = $this->rpc('tools/list', $cursor ? ['cursor' => $cursor] : [])->assertOk()->json('result');
            $tools->push(...$page['tools']);
            $cursor = $page['nextCursor'] ?? null;
        } while ($cursor);

        return $tools->keyBy('name');
    }

    public function test_tools_point_to_the_design_app(): void
    {
        Passport::actingAs($this->owner, ['mcp:use']);

        $tools = $this->listTools();
        $uri = app(DesignApp::class)->uri();

        $this->assertStringStartsWith('ui://ward-artifacts/design-', $uri);
        $this->assertSame(['resourceUri' => $uri, 'visibility' => ['model', 'app']], $tools['open_design']['_meta']['ui']);
        $this->assertSame($uri, $tools['upsert_artboard']['_meta']['ui']['resourceUri']);
        // Ferramentas só do app: o modelo não as vê.
        $this->assertSame(['app'], $tools['design_live']['_meta']['ui']['visibility']);
        $this->assertSame(['app'], $tools['design_report_view']['_meta']['ui']['visibility']);
    }

    public function test_app_resource_is_a_self_contained_mcp_app(): void
    {
        Passport::actingAs($this->owner, ['mcp:use']);

        $content = $this->rpc('resources/read', ['uri' => app(DesignApp::class)->uri()])
            ->assertOk()
            ->json('result.contents.0');

        $this->assertSame('text/html;profile=mcp-app', $content['mimeType']);
        $this->assertStringContainsString('window.createMcpApp', $content['text']);
        $this->assertStringContainsString('window.WardDesignCanvas = DesignCanvas', $content['text']);
        $this->assertStringContainsString('data-design-canvas', $content['text']);
        $this->assertStringNotContainsString('/build/', $content['text']);

        $csp = $content['_meta']['ui']['csp'];
        $this->assertSame([app(ContentGateway::class)->origin()], $csp['frameDomains']);
        $this->assertSame(['http://localhost'], $csp['connectDomains']);
    }

    public function test_open_design_returns_the_app_state(): void
    {
        WardServer::actingAs($this->owner)->tool(OpenDesignTool::class, ['design' => $this->design->url()])
            ->assertOk()
            ->assertSee('aberto no chat')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('design', $this->design->uuid)
                ->where('app.design', $this->design->uuid)
                ->where('app.title', 'App mobile')
                ->where('app.state.boards.0.name', 'Home')
                ->where('app.contentOrigin', 'http://content.localhost')
                ->has('app.liveUrl')
                ->has('app.issuedAt')
                ->etc());
    }

    public function test_open_design_follows_view_permissions(): void
    {
        $other = User::factory()->create();

        WardServer::actingAs($other)->tool(OpenDesignTool::class, ['design' => $this->design->uuid])
            ->assertHasErrors(['Design não encontrado.']);

        app(SetArtifactVisibility::class)->base($this->design, Visibility::Users);
        WardServer::actingAs($other)->tool(OpenDesignTool::class, ['design' => $this->design->uuid])->assertOk();
    }

    public function test_live_tool_only_sends_the_state_when_it_changed(): void
    {
        $revision = $this->design->fresh()->design_revision;

        WardServer::actingAs($this->owner)->tool(DesignLiveTool::class, ['design' => $this->design->uuid, 'rev' => $revision])
            ->assertOk()
            ->assertStructuredContent(['changed' => false]);

        WardServer::actingAs($this->owner)->tool(DesignLiveTool::class, ['design' => $this->design->uuid, 'rev' => $revision - 1])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('changed', true)
                ->where('app.state.revision', $revision)
                ->etc());
    }

    public function test_selection_in_the_app_shows_up_in_get_design(): void
    {
        WardServer::actingAs($this->owner)->tool(DesignReportViewTool::class, ['design' => $this->design->uuid, 'board' => 'Home'])
            ->assertOk();

        WardServer::actingAs($this->owner)->tool(GetDesignTool::class, ['design' => $this->design->uuid])
            ->assertSee('A pessoa está com o artboard "Home" selecionado')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('viewing.board', 'Home')->etc());

        // A seleção é por pessoa.
        $admin = User::factory()->admin()->create();
        WardServer::actingAs($admin)->tool(GetDesignTool::class, ['design' => $this->design->uuid])
            ->assertDontSee('selecionado');

        // Sem seleção ou artboard inexistente.
        WardServer::actingAs($this->owner)->tool(DesignReportViewTool::class, ['design' => $this->design->uuid, 'board' => 'Nada'])
            ->assertHasErrors();
        WardServer::actingAs($this->owner)->tool(DesignReportViewTool::class, ['design' => $this->design->uuid, 'board' => ''])
            ->assertOk();
        WardServer::actingAs($this->owner)->tool(GetDesignTool::class, ['design' => $this->design->uuid])
            ->assertDontSee('selecionado');
    }

    public function test_artboards_can_be_framed_by_claude_but_artifacts_cannot(): void
    {
        $gateway = app(ContentGateway::class);

        $csp = $this->get($gateway->boardUrl($this->design->boards()->sole()))->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors http://localhost https://claude.ai', $csp);
        $this->assertStringContainsString('https://*.claudemcpcontent.com', $csp);

        $page = app(PublishArtifact::class)->handle($this->owner, 'Pagina', 'Onboarding', $this->htmlFile());
        $csp = $this->get($gateway->url($page->currentVersion))->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('claude', $csp);
    }

    public function test_mcp_requests_are_logged_without_arguments(): void
    {
        Passport::actingAs($this->owner, ['mcp:use']);
        $log = Mockery::spy();
        Log::shouldReceive('channel')->with('mcp')->andReturn($log);

        $this->rpc('initialize', [
            'protocolVersion' => '2026-01-26',
            'capabilities' => ['extensions' => ['io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']]]],
            'clientInfo' => ['name' => 'claude-ai', 'version' => '1'],
        ])->assertOk();
        $this->rpc('resources/read', ['uri' => app(DesignApp::class)->uri()])->assertOk();
        $this->rpc('tools/call', ['name' => 'get_design', 'arguments' => ['design' => $this->design->uuid]])->assertOk();

        $log->shouldHaveReceived('info')->with('initialize', Mockery::on(fn ($c) => $c['client'] === 'claude-ai' && $c['mcp_apps'] === true && $c['user'] === 'nam-ward'));
        $log->shouldHaveReceived('info')->with('resources/read', Mockery::on(fn ($c) => str_starts_with($c['uri'], 'ui://')));
        $log->shouldHaveReceived('info')->with('tools/call', Mockery::on(fn ($c) => $c['user'] === 'nam-ward' && $c['tool'] === 'get_design'));
        $this->rpc('tools/list')->assertOk();
        $log->shouldHaveReceived('info')->with('tools/list', Mockery::on(fn ($c) => $c['user'] === 'nam-ward' && array_key_exists('agent', $c)));

        // Protocolo novo: capacidades do cliente em cada pedido.
        $this->rpc('server/discover', ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientInfo' => ['name' => 'claude-ai', 'version' => '1'],
            'io.modelcontextprotocol/clientCapabilities' => [
                'extensions' => ['io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']]],
            ],
        ]], ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'server/discover'])->assertOk();
        $log->shouldHaveReceived('info')->with('server/discover', Mockery::on(fn ($c) => $c['client'] === 'claude-ai'
            && $c['protocol'] === '2026-07-28'
            && $c['mcp_apps'] === true
            && $c['extensions'] === ['io.modelcontextprotocol/ui']));
    }
}
