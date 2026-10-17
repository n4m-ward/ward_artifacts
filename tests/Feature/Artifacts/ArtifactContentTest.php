<?php

namespace Tests\Feature\Artifacts;

use App\Actions\Artifacts\DeleteArtifact;
use App\Actions\Artifacts\PublishArtifact;
use App\Artifacts\ContentGateway;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesArtifactFiles;
use Tests\TestCase;

class ArtifactContentTest extends TestCase
{
    use MakesArtifactFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
    }

    private function publishBundle(): Artifact
    {
        return app(PublishArtifact::class)->handle(User::factory()->create(), 'Jogo', 'Mapas', $this->zipFile([
            'index.html' => '<!doctype html><script src="app.js"></script>',
            'app.js' => 'fetch("assets/tiles.json")',
            'assets/tiles.json' => '{"a":1}',
            'assets/mage.png' => "\x89PNG fake",
        ]));
    }

    private function contentUrl(Artifact $artifact, ?string $path = null): string
    {
        return app(ContentGateway::class)->url($artifact->currentVersion, $path);
    }

    public function test_serves_the_entry_with_sandbox_headers(): void
    {
        $artifact = $this->publishBundle();

        $response = $this->get($this->contentUrl($artifact));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('sandbox allow-scripts', $csp);
        $this->assertStringContainsString('allow-same-origin', $csp);
        $this->assertStringContainsString('frame-ancestors http://localhost', $csp);
        $this->assertSame('<!doctype html><script src="app.js"></script>', $response->streamedContent());
    }

    public function test_serves_relative_assets_with_their_types(): void
    {
        $artifact = $this->publishBundle();
        $base = dirname($this->contentUrl($artifact));

        $this->get("{$base}/assets/tiles.json")->assertOk()->assertHeader('Content-Type', 'application/json; charset=utf-8');
        $this->get("{$base}/app.js")->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $this->get("{$base}/assets/mage.png")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get("{$base}/assets/nada.png")->assertNotFound();
    }

    public function test_blocks_traversal(): void
    {
        $artifact = $this->publishBundle();
        $base = dirname($this->contentUrl($artifact));

        $this->get("{$base}/../../.env")->assertNotFound();
        $this->get("{$base}/assets/%2e%2e/%2e%2e/x")->assertNotFound();
    }

    public function test_rejects_tampered_and_expired_tokens(): void
    {
        $artifact = $this->publishBundle();
        $url = $this->contentUrl($artifact);

        $this->get(preg_replace('/\/c\/v(\d+)\./', '/c/v999.', $url))->assertNotFound();
        $this->get(preg_replace('/\.(\d+)\./', '.9999999999.', $url))->assertNotFound();

        $this->travel(181)->minutes();
        $this->get($url)->assertNotFound();
    }

    public function test_deleted_artifact_stops_being_served(): void
    {
        $artifact = $this->publishBundle();
        $url = $this->contentUrl($artifact);

        app(DeleteArtifact::class)->handle($artifact);

        $this->get($url)->assertNotFound();
    }

    public function test_content_host_only_serves_content(): void
    {
        $this->get('http://content.localhost/login')->assertNotFound();
        $this->get('http://content.localhost/dashboard')->assertNotFound();
        $this->get('http://content.localhost/uploads/'.str_repeat('a', 48))->assertNotFound();
    }

    public function test_content_route_does_not_exist_on_the_app_host(): void
    {
        $artifact = $this->publishBundle();
        $url = str_replace('http://content.localhost', 'http://localhost', $this->contentUrl($artifact));

        $this->get($url)->assertNotFound();
    }

    public function test_content_responses_do_not_start_a_session(): void
    {
        $artifact = $this->publishBundle();

        $this->get($this->contentUrl($artifact))->assertOk()->assertCookieMissing(config('session.cookie'));
    }

    public function test_sandbox_is_fully_closed_without_a_content_host(): void
    {
        config(['ward.artifacts.content_url' => null]);

        $gateway = app(ContentGateway::class);

        $this->assertFalse($gateway->isIsolated());
        $this->assertStringNotContainsString('allow-same-origin', $gateway->sandbox());
    }
}
