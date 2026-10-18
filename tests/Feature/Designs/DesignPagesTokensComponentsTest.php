<?php

namespace Tests\Feature\Designs;

use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\UpdateDesignLayout;
use App\Actions\Designs\UpsertArtboard;
use App\Artifacts\ContentGateway;
use App\Designs\DesignState;
use App\Mcp\Servers\WardServer;
use App\Mcp\Tools\GetDesignTool;
use App\Mcp\Tools\SetDesignTokensTool;
use App\Mcp\Tools\UpsertArtboardTool;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DesignPagesTokensComponentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Artifact $design;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create(['username' => 'nam-ward']);
        $this->design = app(CreateDesign::class)->handle($this->owner, 'App mobile', 'Onboarding');
    }

    private function board(string $name, string $html, array $extra = [])
    {
        return app(UpsertArtboard::class)->handle($this->design, $this->owner, ['name' => $name, 'html' => $html, 'w' => 390, 'h' => 300] + $extra);
    }

    public function test_pages_group_boards_and_deleting_one_moves_its_content_to_the_first(): void
    {
        app(UpdateDesignLayout::class)->handle($this->design, [], [], [
            ['key' => 'telas', 'name' => 'Telas'],
            ['key' => 'checkout', 'name' => 'Checkout'],
        ]);
        $this->board('Home', '<!doctype html><p>a</p>');
        $pay = $this->board('Pagar', '<!doctype html><p>b</p>', ['page' => 'checkout']);

        $state = app(DesignState::class)->toArray($this->design->fresh());
        $this->assertSame(['telas', 'checkout'], array_column($state['pages'], 'key'));
        $this->assertSame('checkout', collect($state['boards'])->firstWhere('name', 'Pagar')['page']);

        $this->expectExceptionMessage('Página não encontrada: "nada"');
        try {
            $this->board('X', '<!doctype html><p>x</p>', ['page' => 'nada']);
        } finally {
            app(UpdateDesignLayout::class)->handle($this->design, [], [], [['key' => 'checkout', 'delete' => true]]);
            $this->assertNull($pay->fresh()->page);
            $this->assertSame(1, $this->design->pages()->count());
        }
    }

    public function test_tokens_and_components_are_rendered_into_the_served_board(): void
    {
        $this->board('Navbar', '<!doctype html><html><head><style>.nav{color:var(--ink)}</style><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter"></head><body><nav class="nav">Ward</nav></body></html>');
        $home = $this->board('Home', '<!doctype html><html><head><title>Home</title></head><body><ward-include name="Navbar"></ward-include><p>conteudo</p><ward-include name="Sumido"></ward-include></body></html>');

        $this->assertSame(['Navbar', 'Sumido'], $home->includes);

        WardServer::actingAs($this->owner)->tool(SetDesignTokensTool::class, ['design' => $this->design->uuid, 'css' => '--ink: #1c1917; --accent: #c2410c;'])
            ->assertOk()->assertSee('Tokens salvos (2 variável(is))');

        $html = $this->get(app(ContentGateway::class)->boardUrl($home->fresh()))->assertOk()->getContent();

        $this->assertStringContainsString('<style id="ward-tokens">:root{--ink: #1c1917; --accent: #c2410c;}</style>', $html);
        $this->assertStringContainsString('<style>.nav{color:var(--ink)}</style>', $html);
        $this->assertStringContainsString('fonts.googleapis.com/css2?family=Inter', $html);
        $this->assertStringContainsString('<div data-ward-component="Navbar" style="display: contents"><nav class="nav">Ward</nav></div><p>conteudo</p>', $html);
        $this->assertStringContainsString('<!-- ward-include: "Sumido" não encontrado -->', $html);

        // O arquivo salvo continua com o include.
        $this->assertStringContainsString('<ward-include name="Navbar">', Storage::disk('artifacts')->get($home->fresh()->storage_path));
    }

    public function test_board_version_changes_when_a_component_or_the_tokens_change(): void
    {
        $this->board('Navbar', '<!doctype html><nav>v1</nav>');
        $this->board('Home', '<!doctype html><ward-include name="Navbar"></ward-include>');
        $version = fn () => collect(app(DesignState::class)->toArray($this->design->fresh())['boards'])->firstWhere('name', 'Home');

        $before = $version();
        $this->board('Navbar', '<!doctype html><nav>v2</nav>');
        $afterComponent = $version();
        $this->assertNotSame($before['version'], $afterComponent['version']);
        $this->assertNotSame($before['url'], $afterComponent['url']);
        $this->assertSame($before['revision'], $afterComponent['revision']);

        WardServer::actingAs($this->owner)->tool(SetDesignTokensTool::class, ['design' => $this->design->uuid, 'css' => '--a: 1px;'])->assertOk();
        $this->assertNotSame($afterComponent['version'], $version()['version']);
    }

    public function test_components_cannot_include_themselves_forever(): void
    {
        $loop = $this->board('Loop', '<!doctype html><body><p>x</p><ward-include name="Loop"></ward-include></body>');

        $html = $this->get(app(ContentGateway::class)->boardUrl($loop->fresh()))->assertOk()->getContent();

        $this->assertStringContainsString('<!-- ward-include: "Loop" não encontrado -->', $html);
    }

    public function test_tokens_refuse_markup_and_the_ai_sees_pages_and_tokens(): void
    {
        WardServer::actingAs($this->owner)->tool(SetDesignTokensTool::class, ['design' => $this->design->uuid, 'css' => '--a: 1px;</style><script>x</script>'])
            ->assertHasErrors(['Os tokens são só declarações CSS (sem <style> ou <script>).']);

        app(UpdateDesignLayout::class)->handle($this->design, [], [], [['key' => 'fluxo', 'name' => 'Fluxo']]);
        WardServer::actingAs($this->owner)->tool(UpsertArtboardTool::class, [
            'design' => $this->design->uuid, 'name' => 'Home', 'html' => '<!doctype html><ward-include name="Nav"></ward-include>', 'w' => 390, 'h' => 844, 'page' => 'fluxo',
        ])->assertOk();

        WardServer::actingAs($this->owner)->tool(GetDesignTool::class, ['design' => $this->design->uuid])
            ->assertSee('página "Fluxo", usa Nav')
            ->assertSee('Páginas: Fluxo (fluxo).')
            ->assertSee('Sem tokens');
    }

    public function test_canvas_edits_pages_and_tokens(): void
    {
        $home = $this->board('Home', '<!doctype html><p>a</p>');

        $this->actingAs($this->owner)->postJson(route('designs.edit', $this->design->uuid), ['ops' => [
            ['op' => 'page.upsert', 'key' => 'principal', 'name' => 'Página 1', 'sort' => 0],
            ['op' => 'page.upsert', 'key' => 'p2', 'name' => 'Página 2', 'sort' => 1],
            ['op' => 'board.update', 'id' => $home->id, 'page' => 'p2'],
            ['op' => 'design.tokens', 'css' => '--ink: #000;'],
        ]])->assertOk()
            ->assertJsonPath('state.pages.1.name', 'Página 2')
            ->assertJsonPath('state.boards.0.page', 'p2')
            ->assertJsonPath('state.tokens', '--ink: #000;')
            ->assertJsonPath('results.3.before', null);

        $this->actingAs($this->owner)->postJson(route('designs.edit', $this->design->uuid), ['ops' => [
            ['op' => 'board.update', 'id' => $home->id, 'page' => ''],
        ]])->assertOk()->assertJsonPath('state.boards.0.page', null);
    }
}
