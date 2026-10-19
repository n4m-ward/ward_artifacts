<?php

namespace Tests\Feature\Mcp;

use App\Actions\Designs\CreateDesign;
use App\Mcp\Servers\WardServer;
use App\Mcp\Tools\GetDesignTool;
use App\Mcp\Tools\UpdateDesignLayoutTool;
use App\Mcp\Tools\UpsertArtboardTool;
use App\Models\Artifact;
use App\Models\DesignBoard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * Protótipo: ligações entre artboards lidas do HTML e avisos de canvas
 * (artboards sobrepostos, ligações para telas que não existem).
 */
class DesignPrototypeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Artifact $design;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->user = User::factory()->create(['username' => 'dono']);
        $this->design = app(CreateDesign::class)->handle($this->user, 'Loja', 'App');
    }

    private function upsert(array $data)
    {
        return WardServer::actingAs($this->user)->tool(UpsertArtboardTool::class, ['design' => $this->design->uuid, ...$data]);
    }

    public function test_links_are_read_from_href_and_data_attributes(): void
    {
        $html = <<<'HTML'
            <!doctype html><body>
            <a href="board:Checkout">Pagar</a>
            <a href="board:Meu%20Perfil">Perfil</a>
            <button data-ward-to="Checkout" data-ward-transition="fade">De novo</button>
            <div data-ward-overlay='Filtro'>Filtrar</div>
            <button data-ward-back>Voltar</button>
            <a href="https://example.com">Fora</a>
            </body>
            HTML;

        $this->assertSame([
            ['to' => 'Checkout', 'overlay' => false],
            ['to' => 'Meu Perfil', 'overlay' => false],
            ['to' => 'Filtro', 'overlay' => true],
        ], DesignBoard::linksIn($html));
    }

    public function test_upsert_stores_links_and_reports_broken_ones(): void
    {
        $this->upsert(['name' => 'Home', 'html' => '<!doctype html><button data-ward-to="Checkout">Pagar</button>', 'w' => 390, 'h' => 844])
            ->assertSee('Ligações para artboards que não existem: "Home" → "Checkout"')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('board.links.0.to', 'Checkout')
                ->where('board.interactive', true)
                ->where('broken_links.0.to', 'Checkout')
                ->etc());

        $this->upsert(['name' => 'Checkout', 'html' => '<!doctype html><button data-ward-back>Voltar</button>', 'w' => 390, 'h' => 844])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('broken_links', [])->where('overlaps', [])->etc());

        WardServer::actingAs($this->user)->tool(GetDesignTool::class, ['design' => $this->design->uuid])
            ->assertSee('liga a Checkout');
    }

    public function test_new_boards_never_land_on_top_of_another(): void
    {
        $this->upsert(['name' => 'A', 'html' => '<!doctype html><p>a</p>', 'w' => 390, 'h' => 844]);
        // Um artboard alto fora da primeira fileira, exatamente onde o próximo cairia.
        $this->upsert(['name' => 'Alto', 'html' => '<!doctype html><p>b</p>', 'w' => 400, 'h' => 2000, 'x' => 470, 'y' => -1500]);
        $this->upsert(['name' => 'C', 'html' => '<!doctype html><p>c</p>', 'w' => 390, 'h' => 844])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('overlaps', [])->etc());

        $c = $this->design->boards()->where('name', 'C')->sole();
        $this->assertSame([0, 844 + 160], [$c->x, $c->y]);
    }

    public function test_layout_reports_overlapping_boards(): void
    {
        $this->upsert(['name' => 'Home', 'html' => '<!doctype html><p>a</p>', 'w' => 390, 'h' => 844]);
        $this->upsert(['name' => 'Perfil', 'html' => '<!doctype html><p>b</p>', 'w' => 390, 'h' => 844]);

        WardServer::actingAs($this->user)->tool(UpdateDesignLayoutTool::class, [
            'design' => $this->design->uuid,
            'boards' => [['name' => 'Perfil', 'x' => 200, 'y' => 100]],
        ])
            ->assertSee('ATENÇÃO: artboards sobrepostos')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('overlaps', [['Home', 'Perfil']])->etc());

        // Em páginas diferentes não conta.
        WardServer::actingAs($this->user)->tool(UpdateDesignLayoutTool::class, [
            'design' => $this->design->uuid,
            'pages' => [['key' => 'p1', 'name' => 'Um'], ['key' => 'p2', 'name' => 'Dois']],
            'boards' => [['name' => 'Perfil', 'page' => 'p2']],
        ])->assertStructuredContent(fn (AssertableJson $json) => $json->where('overlaps', [])->etc());
    }
}
