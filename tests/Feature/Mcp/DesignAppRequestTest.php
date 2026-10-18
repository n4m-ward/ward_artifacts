<?php

namespace Tests\Feature\Mcp;

use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\UpsertArtboard;
use App\Mcp\Servers\WardServer;
use App\Mcp\Tools\DesignAppRequestTool;
use App\Mcp\Tools\OpenDesignTool;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * O editor completo dentro do MCP App: as ações da página do design passam pela
 * ferramenta design_app_request com o usuário do token, sem cookie nem CSRF.
 */
class DesignAppRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Artifact $design;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create(['username' => 'dono']);
        $this->design = app(CreateDesign::class)->handle($this->owner, 'Loja', 'Ward Café');
        app(UpsertArtboard::class)->handle($this->design, $this->owner, ['name' => 'Home', 'html' => '<!doctype html><h1>Oi</h1>', 'w' => 390, 'h' => 844]);
    }

    private function appRequest(User $user, string $method, string $path, array $body = [])
    {
        return WardServer::actingAs($user)->tool(DesignAppRequestTool::class, ['method' => $method, 'path' => $path, 'body' => $body]);
    }

    public function test_app_config_brings_the_editor_for_the_owner_only(): void
    {
        WardServer::actingAs($this->owner)->tool(OpenDesignTool::class, ['design' => $this->design->uuid])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('app.app', true)
                ->where('app.edit.url', route('designs.edit', $this->design->uuid))
                ->missing('app.edit.csrf')
                ->where('app.collab.user.username', 'dono')
                ->missing('app.collab.printUrl')
                ->etc());

        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create();
        $this->design->forceFill(['visibility' => 'users'])->save();

        WardServer::actingAs($other)->tool(OpenDesignTool::class, ['design' => $this->design->uuid])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('app.edit', null)->has('app.collab')->etc());
        unset($admin);
    }

    public function test_owner_edits_reads_source_and_comments_through_the_tool(): void
    {
        $board = $this->design->boards()->sole();
        $uuid = $this->design->uuid;

        $this->appRequest($this->owner, 'POST', "d/{$uuid}/edit", ['ops' => [['op' => 'board.update', 'id' => $board->id, 'x' => 120, 'y' => 40]]])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 200)->where('body.state.boards.0.x', 120)->etc());

        $this->appRequest($this->owner, 'GET', "d/{$uuid}/boards/{$board->id}/source")
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 200)->where('body.html', '<!doctype html><h1>Oi</h1>')->etc());

        $this->appRequest($this->owner, 'POST', "d/{$uuid}/comments", ['body' => 'Título maior', 'board' => $board->id, 'anchor' => ['bx' => 10, 'by' => 10]])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 200)->has('body.thread')->has('body.state.comments', 1)->etc());

        $this->appRequest($this->owner, 'POST', "d/{$uuid}/view", ['board' => 'Home'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 200)->etc());
    }

    public function test_image_upload_goes_as_base64(): void
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $this->appRequest($this->owner, 'POST', "d/{$this->design->uuid}/assets", ['file' => ['name' => 'logo.png', 'type' => 'image/png', 'base64' => base64_encode($png)]])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 200)->where('body.asset.path', fn ($path) => str_starts_with($path, 'assets/logo-'))->etc());

        $this->assertSame(1, $this->design->assets()->count());
    }

    public function test_only_editor_routes_and_the_controller_permissions(): void
    {
        $other = User::factory()->create();
        $board = $this->design->boards()->sole();

        // Fora da lista do editor.
        $this->appRequest($this->owner, 'GET', 'users')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 404)->etc());
        $this->appRequest($this->owner, 'GET', "artifacts/{$this->design->uuid}/download")
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 404)->etc());

        // Quem não é dono não edita (o controller decide, como na web).
        $this->appRequest($other, 'POST', "d/{$this->design->uuid}/edit", ['ops' => [['op' => 'board.update', 'id' => $board->id, 'x' => 1]]])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 403)->etc());
        $this->assertSame(0, $board->fresh()->x);
    }
}
