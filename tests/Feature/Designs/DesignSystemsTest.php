<?php

namespace Tests\Feature\Designs;

use App\Actions\Artifacts\SetArtifactVisibility;
use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\SaveDesignSystem;
use App\Actions\Designs\UpsertArtboard;
use App\Artifacts\ContentGateway;
use App\Designs\DesignState;
use App\Enums\Visibility;
use App\Mcp\Servers\WardServer;
use App\Mcp\Tools\GetDesignSystemTool;
use App\Mcp\Tools\ImportComponentTool;
use App\Mcp\Tools\ListDesignSystemsTool;
use App\Mcp\Tools\PublishComponentTool;
use App\Mcp\Tools\SaveDesignSystemTool;
use App\Mcp\Tools\SetDesignTokensTool;
use App\Mcp\Tools\UpsertArtboardTool;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DesignSystemsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Artifact $system;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create(['username' => 'nam-ward']);
        $this->system = app(SaveDesignSystem::class)->handle($this->owner, 'Ward Café', ['title' => 'Ward Café UI', 'description' => 'Tema quente.']);

        WardServer::actingAs($this->owner)->tool(UpsertArtboardTool::class, [
            'design' => 'nam-ward/ward-cafe', 'name' => 'Botao', 'title' => 'Botão primário', 'w' => 240, 'h' => 52,
            'html' => '<!doctype html><html><head><style>.btn{background:var(--accent)}</style></head><body><button class="btn">Pedir</button><img src="assets/x.png" alt=""></body></html>',
            'description' => 'Ação principal.', 'tags' => 'botão, cta, botão',
        ])->assertOk();
        WardServer::actingAs($this->owner)->tool(SetDesignTokensTool::class, ['design' => 'nam-ward/ward-cafe', 'css' => '--accent: #b45309;'])->assertOk();
    }

    public function test_one_design_system_per_project_visible_to_everyone_logged_in(): void
    {
        $this->assertTrue($this->system->isDesignSystem());
        $this->assertSame(Visibility::Users, $this->system->visibility);

        $again = app(SaveDesignSystem::class)->handle($this->owner, 'Ward Café', ['docs' => '## Botões']);
        $this->assertSame($this->system->id, $again->id);
        $this->assertSame('## Botões', $again->docs);

        $component = $this->system->boards()->sole();
        $this->assertSame(['Ação principal.', 'botão, cta'], [$component->description, $component->tags]);

        // Nunca fica "só eu"; link público temporário continua valendo.
        $this->expectException(InvalidArgumentException::class);
        app(SetArtifactVisibility::class)->base($this->system, Visibility::Owner);
    }

    public function test_anyone_logged_in_finds_and_reads_design_systems(): void
    {
        $other = User::factory()->create();

        WardServer::actingAs($other)->tool(ListDesignSystemsTool::class, ['query' => 'cta'])
            ->assertSee('nam-ward/ward-cafe: "Ward Café UI" (1 componente(s)) — Tema quente.');
        WardServer::actingAs($other)->tool(ListDesignSystemsTool::class, ['query' => 'nada-disso'])->assertSee('Nenhum design system');

        WardServer::actingAs($other)->tool(GetDesignSystemTool::class, ['design_system' => 'nam-ward/ward-cafe'])
            ->assertSee('Botao (240×52) "Botão primário": Ação principal. [botão, cta]')
            ->assertSee('Tokens: --accent: #b45309;');

        $this->actingAs($other)->get($this->system->path())->assertOk()->assertSee('Documentação');
        $this->actingAs($other)->get(route('design-systems.index'))->assertOk()->assertSee('Ward Café UI');

        // Quem não é dono não edita.
        WardServer::actingAs($other)->tool(SaveDesignSystemTool::class, ['project' => 'Ward Café', 'title' => 'Meu'])->assertOk();
        $this->assertSame('Ward Café UI', $this->system->fresh()->title);
        WardServer::actingAs($other)->tool(UpsertArtboardTool::class, ['design' => 'nam-ward/ward-cafe', 'name' => 'Botao', 'html' => '<p>x</p>'])
            ->assertHasErrors(['Só o dono do design ou um admin pode alterá-lo.']);
    }

    public function test_link_import_follows_the_original_and_copy_is_independent(): void
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        Storage::disk('artifacts')->put("{$this->system->uuid}/assets/x.png", $png);
        $this->system->assets()->create(['name' => 'x.png', 'mime' => 'image/png', 'size_bytes' => strlen($png)]);

        $user = User::factory()->create();
        $design = app(CreateDesign::class)->handle($user, 'Loja', 'Loja');

        WardServer::actingAs($user)->tool(ImportComponentTool::class, ['design' => $design->uuid, 'design_system' => 'nam-ward/ward-cafe', 'component' => 'Botao'])
            ->assertOk()->assertSee('vinculado');
        WardServer::actingAs($user)->tool(ImportComponentTool::class, ['design' => $design->uuid, 'design_system' => 'nam-ward/ward-cafe', 'component' => 'Botao', 'mode' => 'copy', 'name' => 'Botao Loja'])
            ->assertOk()->assertSee('cópia');

        $design->refresh();
        $this->assertSame($this->system->id, $design->uses_design_system_id);
        $linked = $design->boards()->where('name', 'Botao')->sole();
        $copy = $design->boards()->where('name', 'Botao Loja')->sole();

        $this->assertSame(['nam-ward/ward-cafe:Botao'], $linked->includes);
        $html = $this->get(app(ContentGateway::class)->boardUrl($linked))->assertOk()->getContent();
        $this->assertStringContainsString('data-ward-system="nam-ward/ward-cafe"', $html);
        $this->assertStringContainsString('--accent: #b45309;', $html);
        $this->assertStringContainsString('.btn{background:var(--accent)}', $html);
        // A imagem do componente vem do design system dele (URL absoluta).
        $this->assertMatchesRegularExpression('#src="http://content\.localhost/c/b\d+\.[^"]+/assets/x\.png"#', $html);

        // A cópia leva a imagem para o design de destino.
        $this->assertStringContainsString('assets/x-', Storage::disk('artifacts')->get($copy->storage_path));
        $this->assertSame(1, $design->assets()->count());

        // Mudou o original: a versão do vinculado muda, a da cópia não.
        $state = fn () => collect(app(DesignState::class)->toArray($design->fresh())['boards'])->keyBy('name');
        $before = $state();
        app(UpsertArtboard::class)->handle($this->system, $this->owner, ['name' => 'Botao', 'html' => '<!doctype html><button>v2</button>']);
        $after = $state();
        $this->assertNotSame($before['Botao']['version'], $after['Botao']['version']);
        $this->assertSame($before['Botao Loja']['version'], $after['Botao Loja']['version']);
        $this->assertTrue($after['Botao']['transparent']);
    }

    public function test_publish_component_promotes_an_artboard(): void
    {
        $design = app(CreateDesign::class)->handle($this->owner, 'App', 'Ward Café');
        app(UpsertArtboard::class)->handle($design, $this->owner, ['name' => 'Card', 'html' => '<!doctype html><div>card</div>', 'w' => 320, 'h' => 200]);

        WardServer::actingAs($this->owner)->tool(PublishComponentTool::class, [
            'design' => $design->uuid, 'board' => 'Card', 'design_system' => 'nam-ward/ward-cafe', 'description' => 'Cartão de produto', 'tags' => 'card',
        ])->assertOk()->assertSee('Componente "Card" salvo no design system nam-ward/ward-cafe');

        $this->assertSame('Cartão de produto', $this->system->boards()->where('name', 'Card')->sole()->description);

        $other = User::factory()->create();
        $theirs = app(CreateDesign::class)->handle($other, 'X', 'X');
        app(UpsertArtboard::class)->handle($theirs, $other, ['name' => 'Card', 'html' => '<!doctype html><div>x</div>', 'w' => 10 + 40, 'h' => 50]);
        WardServer::actingAs($other)->tool(PublishComponentTool::class, ['design' => $theirs->uuid, 'board' => 'Card', 'design_system' => 'nam-ward/ward-cafe'])
            ->assertHasErrors(['Só o dono do design ou um admin pode alterá-lo.']);
    }

    public function test_canvas_ops_for_docs_components_and_import(): void
    {
        $this->actingAs($this->owner)->postJson(route('designs.edit', $this->system->uuid), ['ops' => [
            ['op' => 'design.meta', 'title' => 'Ward Café UI 2', 'docs' => "## Oi\n\n<script>x</script>"],
            ['op' => 'board.describe', 'id' => $this->system->boards()->sole()->id, 'tags' => 'novo'],
        ]])->assertOk()->assertJsonPath('state.boards.0.tags', 'novo');

        $docs = $this->actingAs($this->owner)->getJson(route('design-systems.docs', $this->system->uuid))->assertOk();
        $this->assertStringContainsString('<h2>Oi</h2>', $docs->json('html'));
        $this->assertStringNotContainsString('<script>', $docs->json('html'));

        $design = app(CreateDesign::class)->handle($this->owner, 'App', 'Outro');
        $this->actingAs($this->owner)->postJson(route('designs.edit', $design->uuid), ['ops' => [
            ['op' => 'component.import', 'system' => 'nam-ward/ward-cafe', 'component' => 'Botao', 'mode' => 'link', 'x' => 10, 'y' => 20],
        ]])->assertOk()->assertJsonPath('state.boards.0.name', 'Botao')->assertJsonPath('state.boards.0.transparent', true);

        $this->actingAs($this->owner)->getJson(route('design-systems.search', ['q' => 'ward']))
            ->assertOk()->assertJsonPath('design_systems.0.ref', 'nam-ward/ward-cafe');
    }

    public function test_create_page_makes_a_design_system_for_a_project(): void
    {
        $this->actingAs($this->owner);

        Volt::test('design-systems.index')
            ->set('project', 'Loja')
            ->set('title', 'Loja UI')
            ->set('description', 'Componentes da loja')
            ->call('create')
            ->assertRedirect();

        $this->assertTrue(Artifact::where('title', 'Loja UI')->sole()->isDesignSystem());
    }
}
