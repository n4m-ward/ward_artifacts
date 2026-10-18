<?php

namespace Tests\Feature\Designs;

use App\Actions\Artifacts\SetArtifactVisibility;
use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\UpsertArtboard;
use App\Artifacts\ContentGateway;
use App\Enums\Visibility;
use App\Mcp\Servers\WardServer;
use App\Mcp\Tools\GetDesignTool;
use App\Mcp\Tools\ResolveCommentTool;
use App\Models\Artifact;
use App\Models\DesignBoard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class DesignCommentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Artifact $design;

    private DesignBoard $home;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create(['username' => 'nam-ward', 'name' => 'Gabriel']);
        $this->design = app(CreateDesign::class)->handle($this->owner, 'App mobile', 'Onboarding');
        $this->home = app(UpsertArtboard::class)->handle($this->design, $this->owner, [
            'name' => 'Home', 'html' => '<!doctype html><h1>Oi</h1><script>quebra()</script>', 'w' => 390, 'h' => 844,
        ]);
    }

    private function comment(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->postJson(route('designs.comments.store', $this->design->uuid), $data);
    }

    public function test_comment_anchored_to_an_element_shows_up_for_the_ai(): void
    {
        $revision = $this->design->fresh()->design_revision;

        $this->comment([
            'body' => 'Título maior, por favor',
            'board' => $this->home->id,
            'anchor' => ['path' => '0', 'tag' => 'h1', 'text' => 'Oi', 'html' => '<h1>Oi</h1>', 'bx' => 40, 'by' => 30, 'evil' => 'x'],
        ])->assertOk()
            ->assertJsonPath('state.comments.0.number', 1)
            ->assertJsonPath('state.comments.0.anchor.tag', 'h1')
            ->assertJsonMissingPath('state.comments.0.anchor.evil')
            ->assertJsonPath('state.revision', $revision + 1);

        $this->comment(['body' => 'E o subtítulo?', 'parent' => $this->design->comments()->sole()->id])->assertOk();

        WardServer::actingAs($this->owner)->tool(GetDesignTool::class, ['design' => $this->design->uuid])
            ->assertSee('1 comentário(s) aberto(s)')
            ->assertSee('#1 em artboard "Home", elemento <h1> (caminho 0) "Oi"')
            ->assertSee('Gabriel: E o subtítulo?')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('comments.0.messages.1.body', 'E o subtítulo?')->etc());
    }

    public function test_canvas_comments_need_a_point_and_bodies_cannot_be_empty(): void
    {
        $this->comment(['body' => 'Fluxo novo aqui', 'x' => 1200, 'y' => -300])->assertOk()->assertJsonPath('state.comments.0.x', 1200);
        $this->comment(['body' => 'Sem lugar'])->assertUnprocessable();
        $this->comment(['body' => '   ', 'x' => 1, 'y' => 1])->assertUnprocessable();
        $this->comment(['body' => 'Artboard sumiu', 'board' => 999999])->assertUnprocessable();
    }

    public function test_viewers_can_comment_but_only_author_or_editor_can_resolve_or_delete(): void
    {
        $viewer = User::factory()->create();
        $stranger = User::factory()->create();

        $this->comment(['body' => 'Privado', 'x' => 0, 'y' => 0], $stranger)->assertForbidden();

        app(SetArtifactVisibility::class)->base($this->design, Visibility::Users);
        $thread = $this->comment(['body' => 'Mudar a cor', 'board' => $this->home->id], $viewer)->assertOk()->json('thread');

        $this->actingAs($stranger)->patchJson(route('designs.comments.update', [$this->design->uuid, $thread]), ['resolved' => true])->assertForbidden();
        $this->actingAs($viewer)->patchJson(route('designs.comments.update', [$this->design->uuid, $thread]), ['resolved' => true])
            ->assertOk()->assertJsonPath('state.comments.0.resolved', true);
        $this->actingAs($this->owner)->deleteJson(route('designs.comments.destroy', [$this->design->uuid, $thread]))
            ->assertOk()->assertJsonCount(0, 'state.comments');
    }

    public function test_ai_answers_and_resolves_with_resolve_comment(): void
    {
        $this->comment(['body' => 'Fundo mais escuro', 'board' => $this->home->id])->assertOk();

        WardServer::actingAs($this->owner)->tool(ResolveCommentTool::class, ['design' => $this->design->uuid, 'comment' => 1, 'message' => 'Escureci o fundo.'])
            ->assertOk()->assertSee('Comentário #1 resolvido');

        $thread = $this->design->comments()->whereNull('parent_id')->sole();
        $this->assertNotNull($thread->resolved_at);
        $this->assertSame(['Escureci o fundo.', 'ai'], [$thread->replies->sole()->body, $thread->replies->sole()->source]);

        WardServer::actingAs($this->owner)->tool(GetDesignTool::class, ['design' => $this->design->uuid])->assertDontSee('comentário(s) aberto(s)');
        WardServer::actingAs($this->owner)->tool(ResolveCommentTool::class, ['design' => $this->design->uuid, 'comment' => 7])->assertHasErrors(['Comentário #7 não encontrado neste design.']);

        // Quem só vê o design pode responder, mas não resolver.
        $viewer = User::factory()->create();
        app(SetArtifactVisibility::class)->base($this->design, Visibility::Users);
        WardServer::actingAs($viewer)->tool(ResolveCommentTool::class, ['design' => $this->design->uuid, 'comment' => 1, 'message' => 'ok', 'resolve' => false])->assertOk();
        WardServer::actingAs($viewer)->tool(ResolveCommentTool::class, ['design' => $this->design->uuid, 'comment' => 1])->assertHasErrors();
    }

    public function test_view_report_tells_the_ai_what_the_person_sees(): void
    {
        app(UpsertArtboard::class)->handle($this->design, $this->owner, [
            'name' => 'Checkout', 'html' => '<!doctype html><p>pagar</p>', 'w' => 390, 'h' => 844,
        ]);

        $this->actingAs($this->owner)->postJson(route('designs.view', $this->design->uuid), [
            'board' => 'Home',
            'element' => ['path' => '0', 'tag' => 'h1', 'text' => 'Oi', 'html' => '<h1>Oi</h1>'],
            'visible' => ['Home', 'Checkout', 'Inventado'],
            'errors' => [['board' => 'Home', 'message' => 'ReferenceError: quebra is not defined']],
        ])->assertOk();

        WardServer::actingAs($this->owner)->tool(GetDesignTool::class, ['design' => $this->design->uuid])
            ->assertSee('A pessoa está com o artboard "Home" selecionado no canvas, no elemento <h1> (caminho 0 a partir do <body>): "Oi".')
            ->assertSee('Artboards visíveis na tela dela: Home, Checkout.')
            ->assertSee('Erro de script no artboard "Home": ReferenceError: quebra is not defined');
    }

    public function test_guests_never_get_comments_in_the_live_state(): void
    {
        $this->comment(['body' => 'Interno', 'x' => 0, 'y' => 0])->assertOk();

        $this->actingAs($this->owner)->getJson(route('designs.live', $this->design->uuid))->assertJsonPath('comments.0.messages.0.body', 'Interno');

        app(SetArtifactVisibility::class)->makePublic($this->design, 1);
        $this->app['auth']->forgetGuards();
        $html = $this->get($this->design->path())->getContent();
        preg_match('#<script type="application/json" id="ward-design-config">(.*?)</script>#s', $html, $match);
        $config = json_decode($match[1], true);

        $this->assertNull($config['state']['comments']);
        $this->assertNull($config['collab']);
        $this->get($config['liveUrl'].'&rev=0')->assertOk()->assertJsonPath('comments', null);
    }

    public function test_board_runtime_is_injected_at_the_top_of_the_head(): void
    {
        app(UpsertArtboard::class)->handle($this->design, $this->owner, [
            'name' => 'Completo', 'html' => '<!doctype html><html><head lang="pt"><title>x</title></head><body><p>a</p></body></html>', 'w' => 390, 'h' => 844,
        ]);
        $board = $this->design->boards()->where('name', 'Completo')->sole();

        $html = $this->get(app(ContentGateway::class)->boardUrl($board))->getContent();

        $this->assertStringStartsWith('<!doctype html><html><head lang="pt"><script>', $html);
        $this->assertStringContainsString("ward: 'picked'", $html);
    }
}
