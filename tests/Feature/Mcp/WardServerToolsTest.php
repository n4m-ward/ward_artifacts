<?php

namespace Tests\Feature\Mcp;

use App\Actions\Artifacts\PublishArtifact;
use App\Actions\Artifacts\RecordArtifactView;
use App\Actions\Artifacts\SetArtifactVisibility;
use App\Enums\Visibility;
use App\Mcp\Servers\WardServer;
use App\Mcp\Tools\GetArtifactSourceTool;
use App\Mcp\Tools\GetTemplateTool;
use App\Mcp\Tools\InviteUserTool;
use App\Mcp\Tools\ListArtifactsTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\PublishArtifactTool;
use App\Mcp\Tools\RenameArtifactTool;
use App\Mcp\Tools\UpdateArtifactTool;
use App\Mcp\Tools\WhoAmITool;
use App\Models\Artifact;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Concerns\MakesArtifactFiles;
use Tests\TestCase;

class WardServerToolsTest extends TestCase
{
    use MakesArtifactFiles, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->user = User::factory()->create(['username' => 'nam-ward']);
    }

    private function publish(User $owner, string $title = 'Vila', string $html = '<p>v1</p>'): Artifact
    {
        return app(PublishArtifact::class)->handle($owner, $title, 'Mapas', $this->htmlFile($html));
    }

    public function test_templates(): void
    {
        WardServer::actingAs($this->user)->tool(GetTemplateTool::class)
            ->assertOk()
            ->assertSee('@tailwindcss/browser@4.3.3');

        WardServer::actingAs($this->user)->tool(GetTemplateTool::class, ['variant' => 'canvas'])
            ->assertOk()
            ->assertSee('requestAnimationFrame');

        WardServer::actingAs($this->user)->tool(GetTemplateTool::class, ['variant' => 'nope'])
            ->assertHasErrors();
    }

    public function test_publish_inline_html_creates_a_private_artifact(): void
    {
        WardServer::actingAs($this->user)->tool(PublishArtifactTool::class, [
            'title' => 'Vila de Teste',
            'project' => 'Sistema de mapas',
            'html' => '<!doctype html><p>oi</p>',
        ])
            ->assertOk()
            ->assertSee('/nam-ward/sistema-de-mapas/vila-de-teste-')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('visibility', 'owner')
                ->where('version', 1)
                ->where('kind', 'html')
                ->etc());

        $artifact = Artifact::sole();
        $this->assertTrue($artifact->user->is($this->user));
        $this->assertSame(Visibility::Owner, $artifact->visibility);
        $this->assertNull($artifact->public_until);
    }

    public function test_publish_and_source_return_a_single_use_preview_link(): void
    {
        $preview = null;
        WardServer::actingAs($this->user)->tool(PublishArtifactTool::class, [
            'title' => 'Vila', 'project' => 'Mapas', 'html' => '<p>oi</p>',
        ])
            ->assertOk()
            ->assertSee('/p/')
            ->assertStructuredContent(function (AssertableJson $json) use (&$preview) {
                $preview = $json->toArray()['preview_url'];
                $json->etc();
            });

        $artifact = Artifact::sole();

        WardServer::actingAs($this->user)->tool(GetArtifactSourceTool::class, ['artifact' => $artifact->uuid])
            ->assertOk()
            ->assertSee('/p/');

        WardServer::actingAs($this->user)->tool(UpdateArtifactTool::class, ['artifact' => $artifact->uuid, 'html' => '<p>v2</p>'])
            ->assertOk()
            ->assertSee('/p/');

        $this->app['auth']->forgetGuards();
        $this->get($preview)->assertOk()->assertSee('Pré-visualização');
        $this->get($preview)->assertForbidden();
    }

    public function test_publish_rejects_invalid_and_oversized_html(): void
    {
        WardServer::actingAs($this->user)->tool(PublishArtifactTool::class, [
            'title' => 'X', 'project' => 'Y', 'html' => 'sem nenhuma tag',
        ])->assertHasErrors();

        config(['ward.artifacts.mcp_inline_max_bytes' => 10]);

        WardServer::actingAs($this->user)->tool(PublishArtifactTool::class, [
            'title' => 'X', 'project' => 'Y', 'html' => '<p>'.str_repeat('a', 50).'</p>',
        ])->assertHasErrors();

        $this->assertSame(0, Artifact::count());
    }

    public function test_publish_without_html_returns_a_working_upload_link(): void
    {
        $response = WardServer::actingAs($this->user)->tool(PublishArtifactTool::class, [
            'title' => 'Jogo grande', 'project' => 'Mapas',
        ]);

        $response->assertOk()->assertSee('curl');

        $url = null;
        $response->assertStructuredContent(function (AssertableJson $json) use (&$url) {
            $json->has('upload_url')->has('expires_at');
            $url = $json->toArray()['upload_url'];
        });

        $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'text/html'], '<p>grande</p>')->assertCreated();
        $this->assertSame('Jogo grande', Artifact::sole()->title);
    }

    public function test_update_creates_a_new_version_on_the_same_url(): void
    {
        $artifact = $this->publish($this->user);

        WardServer::actingAs($this->user)->tool(UpdateArtifactTool::class, [
            'artifact' => $artifact->url(),
            'html' => '<p>v2</p>',
        ])
            ->assertOk()
            ->assertSee('Versão 2')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('id', $artifact->uuid)->where('version', 2)->etc());
    }

    public function test_update_respects_permissions(): void
    {
        $owner = User::factory()->create();
        $shared = $this->publish($owner, 'Compartilhado');
        app(SetArtifactVisibility::class)->base($shared, Visibility::Users);
        $private = $this->publish($owner, 'Privado');

        WardServer::actingAs($this->user)->tool(UpdateArtifactTool::class, ['artifact' => $shared->uuid, 'html' => '<p>x</p>'])
            ->assertHasErrors(['Só o dono do artifact ou um admin pode enviar novas versões.']);

        WardServer::actingAs($this->user)->tool(UpdateArtifactTool::class, ['artifact' => $private->uuid, 'html' => '<p>x</p>'])
            ->assertHasErrors(['Artifact não encontrado.']);

        $this->assertSame(1, $shared->versions()->count());
    }

    public function test_source_comes_inline_for_small_html(): void
    {
        $artifact = $this->publish($this->user, 'Vila', '<p>código original</p>');

        WardServer::actingAs($this->user)->tool(GetArtifactSourceTool::class, ['artifact' => $artifact->uuid])
            ->assertOk()
            ->assertSee('<p>código original</p>');
    }

    public function test_source_of_a_bundle_is_a_signed_download_link(): void
    {
        $artifact = app(PublishArtifact::class)->handle($this->user, 'Jogo', 'Mapas', $this->zipFile([
            'index.html' => '<p>oi</p>', 'assets/a.json' => '{}',
        ]));

        $download = null;
        WardServer::actingAs($this->user)->tool(GetArtifactSourceTool::class, ['artifact' => $artifact->uuid])
            ->assertOk()
            ->assertSee('curl -sS -o jogo.zip')
            ->assertStructuredContent(function (AssertableJson $json) use (&$download) {
                $download = $json->toArray()['download_url'];
                $json->etc();
            });

        // O link assinado funciona sem sessão; sem assinatura, não.
        $this->app['auth']->forgetGuards();
        $this->assertGuest();
        $this->get($download)->assertOk()->assertDownload('jogo.zip');
        $this->get(route('artifacts.download', $artifact))->assertForbidden();
    }

    public function test_list_artifacts_and_projects(): void
    {
        $this->publish($this->user, 'Meu mapa');
        $other = User::factory()->create();
        $seen = $this->publish($other, 'Visto');
        app(SetArtifactVisibility::class)->base($seen, Visibility::Users);
        app(RecordArtifactView::class)->handle($this->user, $seen);

        WardServer::actingAs($this->user)->tool(ListArtifactsTool::class)
            ->assertOk()->assertSee('Meu mapa')->assertDontSee('Visto');

        WardServer::actingAs($this->user)->tool(ListArtifactsTool::class, ['scope' => 'viewed'])
            ->assertOk()->assertSee('Visto')->assertDontSee('Meu mapa');

        WardServer::actingAs($this->user)->tool(ListArtifactsTool::class, ['scope' => 'all'])
            ->assertHasErrors(['Só admins podem listar todos os artifacts.']);

        WardServer::actingAs(User::factory()->admin()->create())->tool(ListArtifactsTool::class, ['scope' => 'all'])
            ->assertOk()->assertSee('Meu mapa')->assertSee('Visto');

        WardServer::actingAs($this->user)->tool(ListProjectsTool::class)
            ->assertOk()->assertSee('Mapas (mapas): 1 artifact(s)');
    }

    public function test_rename(): void
    {
        $artifact = $this->publish($this->user);

        WardServer::actingAs($this->user)->tool(RenameArtifactTool::class, ['artifact' => $artifact->uuid, 'title' => 'Vila Nova'])
            ->assertOk()->assertSee('/vila-nova-');

        WardServer::actingAs(User::factory()->create())->tool(RenameArtifactTool::class, ['artifact' => $artifact->uuid, 'title' => 'Hack'])
            ->assertHasErrors();

        $this->assertSame('Vila Nova', $artifact->fresh()->title);
    }

    public function test_whoami(): void
    {
        WardServer::actingAs($this->user)->tool(WhoAmITool::class)
            ->assertOk()
            ->assertSee('@nam-ward')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('role', 'player')->etc());
    }

    public function test_only_admins_can_invite_users(): void
    {
        Notification::fake();

        WardServer::actingAs($this->user)->tool(InviteUserTool::class, [
            'name' => 'Ana', 'username' => 'ana', 'email' => 'ana@ward.test',
        ])->assertHasErrors();

        $this->assertNull(User::where('email', 'ana@ward.test')->first());

        WardServer::actingAs(User::factory()->admin()->create())->tool(InviteUserTool::class, [
            'name' => 'Ana', 'username' => 'ana', 'email' => 'ana@ward.test',
        ])
            ->assertOk()
            ->assertSee('/invite/');

        $ana = User::where('email', 'ana@ward.test')->sole();
        $this->assertSame('player', $ana->role->value);
        Notification::assertSentTo($ana, UserInvited::class);
    }

    public function test_invite_tool_is_hidden_from_players(): void
    {
        WardServer::actingAs($this->user)->tools()->assertNotRegistered(InviteUserTool::class);
        WardServer::actingAs(User::factory()->admin()->create())->tools()->assertRegistered(InviteUserTool::class);
    }
}
