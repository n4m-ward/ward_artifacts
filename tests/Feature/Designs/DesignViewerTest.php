<?php

namespace Tests\Feature\Designs;

use App\Actions\Artifacts\CreateArtifactPreview;
use App\Actions\Artifacts\SetArtifactVisibility;
use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\UpdateDesignLayout;
use App\Actions\Designs\UpsertArtboard;
use App\Artifacts\ContentGateway;
use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;
use ZipArchive;

class DesignViewerTest extends TestCase
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

    private function board(string $name, string $text): void
    {
        app(UpsertArtboard::class)->handle($this->design, $this->owner, [
            'name' => $name, 'html' => "<!doctype html><p>{$text}</p>", 'w' => 390, 'h' => 844,
        ]);
    }

    public function test_empty_design_page(): void
    {
        $this->actingAs($this->owner)->get($this->design->path())
            ->assertOk()
            ->assertSee('Nenhum artboard ainda');
    }

    public function test_canvas_page_shows_every_artboard_and_note(): void
    {
        $this->board('Home', 'inicio');
        $this->board('Checkout', 'pagamento');
        app(UpdateDesignLayout::class)->handle($this->design, [], [
            ['key' => 'fluxo', 'kind' => 'title', 'text' => 'Fluxo de compra', 'x' => 0, 'y' => -220],
        ]);

        $this->actingAs($this->owner)->get($this->design->path())
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('2 artboards')
            ->assertSee('ward-design-config', false)
            ->assertSee('Home')
            ->assertSee('Checkout')
            ->assertSee('Fluxo de compra')
            ->assertSee('http://content.localhost/c/b', false)
            ->assertSee('allow-same-origin', false);
    }

    public function test_board_content_is_served_with_sandbox_headers_and_versioned_url(): void
    {
        $this->board('Home', 'v1');
        $gateway = app(ContentGateway::class);
        $board = $this->design->boards()->sole();
        $url = $gateway->boardUrl($board);

        $response = $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        // O runtime da Ward (links board:, erros, inspeção) entra logo depois do doctype
        // (ou no começo do <head>), sem ir para o arquivo salvo.
        $this->assertStringStartsWith('<!doctype html><script>', $response->getContent());
        $this->assertStringEndsWith('</script><p>v1</p>', $response->getContent());
        $this->assertStringContainsString("ward: 'navigate'", $response->getContent());
        $this->assertStringContainsString("ward: 'error'", $response->getContent());
        $this->assertStringContainsString('sandbox allow-scripts', $response->headers->get('Content-Security-Policy'));
        $this->assertStringEndsWith('/1.html', $url);

        $this->board('Home', 'v2');
        $this->assertStringEndsWith('/2.html', $gateway->boardUrl($board->fresh()));

        // Token adulterado ou artboard apagado: 404.
        $this->get(preg_replace('#/c/b(\d+)\.#', '/c/b999.', $url))->assertNotFound();
        $board->delete();
        $this->get($url)->assertNotFound();
    }

    public function test_access_rules_are_the_same_as_artifacts(): void
    {
        $this->board('Home', 'x');

        $this->get($this->design->path())->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get($this->design->path())->assertNotFound();

        app(SetArtifactVisibility::class)->base($this->design, Visibility::Users);
        $this->actingAs(User::factory()->create())->get($this->design->path())->assertOk();

        auth()->logout();
        app(SetArtifactVisibility::class)->makePublic($this->design, 1);
        $this->get($this->design->path())->assertOk();
    }

    public function test_preview_link_renders_the_canvas_once(): void
    {
        $this->board('Home', 'x');
        $url = app(CreateArtifactPreview::class)->handle($this->owner, $this->design);

        $this->get($url)->assertOk()->assertSee('Pré-visualização')->assertSee('http://content.localhost/c/b', false);
        $this->get($url)->assertForbidden();
    }

    public function test_download_is_a_zip_of_the_canvas(): void
    {
        $this->board('Home', 'inicio');

        $response = $this->actingAs($this->owner)->get(route('artifacts.download', $this->design));
        $response->assertOk()->assertDownload('app-mobile.zip');

        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertNotFalse($zip->getFromName('canvas.json'));
        $this->assertSame('<!doctype html><p>inicio</p>', $zip->getFromName('boards/Home.html'));
    }

    public function test_panel_lists_designs(): void
    {
        $this->board('Home', 'x');

        Volt::actingAs($this->owner)->test('artifacts.index')
            ->assertSee('App mobile')
            ->assertSee('design · 1 artboard');
    }
}
