<?php

namespace Tests\Feature\Designs;

use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\UpdateDesignLayout;
use App\Actions\Designs\UpsertArtboard;
use App\Mcp\Servers\WardServer;
use App\Mcp\Tools\GetArtboardImageTool;
use App\Mcp\Tools\GetDesignImageTool;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DesignScreenshotTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Artifact $design;

    /** @var list<array<int, string>> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        config(['ward.designs.screenshots.chrome' => PHP_BINARY]);

        $this->owner = User::factory()->create(['username' => 'nam-ward']);
        $this->design = app(CreateDesign::class)->handle($this->owner, 'App mobile', 'Onboarding');
        app(UpsertArtboard::class)->handle($this->design, $this->owner, [
            'name' => 'Home', 'html' => '<!doctype html><p>inicio</p>', 'w' => 390, 'h' => 844,
        ]);
        app(UpdateDesignLayout::class)->handle($this->design, [], [
            ['key' => 'fluxo', 'kind' => 'title', 'text' => 'Fluxo', 'x' => 0, 'y' => -200],
        ]);
    }

    /**
     * Chrome falso: grava um PNG onde o --screenshot mandar.
     */
    private function fakeChrome(): void
    {
        Process::fake(function (PendingProcess $process) {
            $this->commands[] = $process->command;
            $target = Str::after(collect($process->command)->first(fn ($arg) => str_starts_with($arg, '--screenshot=')), '=');

            $image = imagecreatetruecolor(20, 30);
            imagepng($image, $target);

            return Process::result();
        });
    }

    private function argument(string $name): ?string
    {
        $arg = collect($this->commands[0])->first(fn ($arg) => str_starts_with($arg, "--{$name}="));

        return $arg === null ? null : Str::after($arg, '=');
    }

    public function test_artboard_image_renders_the_board_url_at_its_size(): void
    {
        $this->fakeChrome();

        Passport::actingAs($this->owner, ['mcp:use']);

        $content = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_artboard_image', 'arguments' => ['design' => $this->design->uuid, 'name' => 'Home']],
        ], ['Accept' => 'application/json, text/event-stream'])->assertOk()->json('result.content');

        $this->assertStringContainsString('Print do artboard "Home"', $content[0]['text']);
        $this->assertSame(['image', 'image/jpeg'], [$content[1]['type'], $content[1]['mimeType']]);
        $this->assertSame('image/jpeg', getimagesizefromstring(base64_decode($content[1]['data']))['mime']);

        $this->assertSame('390,844', $this->argument('window-size'));
        $this->assertSame('2', $this->argument('force-device-scale-factor'));
        $this->assertStringStartsWith('http://content.localhost/c/b', end($this->commands[0]));
        $this->assertContains('--no-sandbox', $this->commands[0]);
        // A pasta temporária some depois do print.
        $this->assertDirectoryDoesNotExist(dirname((string) $this->argument('screenshot')));
    }

    public function test_tall_pages_are_scaled_down_to_fit(): void
    {
        app(UpsertArtboard::class)->handle($this->design, $this->owner, [
            'name' => 'Site', 'kind' => 'page', 'html' => '<!doctype html><p>site</p>', 'w' => 1440, 'h' => 7200,
        ]);
        $this->fakeChrome();

        WardServer::actingAs($this->owner)->tool(GetArtboardImageTool::class, ['design' => $this->design->uuid, 'name' => 'Site'])
            ->assertOk();

        $this->assertSame('1440,7200', $this->argument('window-size'));
        $this->assertSame('0.5', $this->argument('force-device-scale-factor'));
    }

    public function test_design_image_photographs_the_static_canvas(): void
    {
        $this->fakeChrome();

        WardServer::actingAs($this->owner)->tool(GetDesignImageTool::class, ['design' => $this->design->uuid])
            ->assertOk()
            ->assertSee('Print do canvas "App mobile"');

        $url = end($this->commands[0]);
        $this->assertStringStartsWith('http://localhost/d/'.$this->design->uuid.'/snapshot?', $url);
        $this->assertStringStartsWith('1600,', (string) $this->argument('window-size'));

        $html = $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->getContent();
        preg_match('#<script type="application/json" id="ward-design-config">(.*?)</script>#s', $html, $match);
        $config = json_decode($match[1], true);

        $this->assertTrue($config['static']);
        $this->assertSame('Home', $config['state']['boards'][0]['name']);
        $this->assertSame('Fluxo', $config['state']['notes'][0]['text']);
        $this->assertArrayNotHasKey('liveUrl', $config);
    }

    public function test_snapshot_page_needs_a_valid_signature(): void
    {
        $this->get('/d/'.$this->design->uuid.'/snapshot')->assertForbidden();
        $this->actingAs($this->owner)->get('/d/'.$this->design->uuid.'/snapshot?expires=9999999999&signature=abc')->assertForbidden();
    }

    public function test_images_follow_view_permissions(): void
    {
        $this->fakeChrome();
        $other = User::factory()->create();

        WardServer::actingAs($other)->tool(GetArtboardImageTool::class, ['design' => $this->design->uuid, 'name' => 'Home'])
            ->assertHasErrors(['Design não encontrado.']);
        WardServer::actingAs($other)->tool(GetDesignImageTool::class, ['design' => $this->design->uuid])
            ->assertHasErrors(['Design não encontrado.']);
        WardServer::actingAs($this->owner)->tool(GetArtboardImageTool::class, ['design' => $this->design->uuid, 'name' => 'Nada'])
            ->assertHasErrors(['Artboard não encontrado: "Nada".']);

        Process::assertNothingRan();
    }

    public function test_without_chrome_the_ai_is_told_to_use_its_browser(): void
    {
        Process::fake();
        config(['ward.designs.screenshots.chrome' => null]);

        WardServer::actingAs($this->owner)->tool(GetArtboardImageTool::class, ['design' => $this->design->uuid, 'name' => 'Home'])
            ->assertHasErrors()
            ->assertSee('abra o canvas (preview_url de get_design)');

        Process::assertNothingRan();
    }

    public function test_a_failed_capture_is_reported(): void
    {
        Process::fake(fn () => Process::result(errorOutput: "pthread_create: Resource temporarily unavailable\nFalhou ao abrir a página", exitCode: 1));

        WardServer::actingAs($this->owner)->tool(GetDesignImageTool::class, ['design' => $this->design->uuid])
            ->assertHasErrors()
            ->assertSee('O Chrome não gerou o print: Falhou ao abrir a página');
    }
}
