<?php

namespace Tests\Feature\Mcp;

use App\Actions\Artifacts\PublishArtifact;
use App\Actions\Artifacts\SetArtifactVisibility;
use App\Enums\Visibility;
use App\Mcp\Servers\WardServer;
use App\Mcp\Tools\CreateDesignTool;
use App\Mcp\Tools\DeleteArtboardTool;
use App\Mcp\Tools\GetArtboardSourceTool;
use App\Mcp\Tools\GetArtifactSourceTool;
use App\Mcp\Tools\GetDesignGuideTool;
use App\Mcp\Tools\GetDesignTool;
use App\Mcp\Tools\ListArtifactsTool;
use App\Mcp\Tools\SaveDesignVersionTool;
use App\Mcp\Tools\UpdateArtifactTool;
use App\Mcp\Tools\UpdateDesignLayoutTool;
use App\Mcp\Tools\UpsertArtboardTool;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Concerns\MakesArtifactFiles;
use Tests\TestCase;

class DesignToolsTest extends TestCase
{
    use MakesArtifactFiles, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->user = User::factory()->create(['username' => 'nam-ward']);
    }

    private function createDesign(): Artifact
    {
        WardServer::actingAs($this->user)->tool(CreateDesignTool::class, ['title' => 'App mobile', 'project' => 'Onboarding'])->assertOk();

        return Artifact::where('type', 'design')->sole();
    }

    public function test_full_design_flow(): void
    {
        WardServer::actingAs($this->user)->tool(CreateDesignTool::class, ['title' => 'App mobile', 'project' => 'Onboarding'])
            ->assertOk()
            ->assertSee('/nam-ward/onboarding/app-mobile-')
            ->assertSee('/p/')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('type', 'design')->where('visibility', 'owner')->etc());

        $design = Artifact::sole();

        WardServer::actingAs($this->user)->tool(GetDesignGuideTool::class)->assertOk()->assertSee('board:')->assertSee('kind=fixed');

        WardServer::actingAs($this->user)->tool(UpsertArtboardTool::class, [
            'design' => $design->url(), 'name' => 'Home', 'html' => '<!doctype html><p>home</p>', 'kind' => 'fixed', 'w' => 390, 'h' => 844,
        ])->assertOk()->assertSee('Artboard "Home" salvo (revisão 1) em 0,0');

        WardServer::actingAs($this->user)->tool(UpsertArtboardTool::class, [
            'design' => $design->uuid, 'name' => 'Checkout', 'html' => '<!doctype html><p>checkout</p>', 'w' => 390, 'h' => 844, 'interactive' => true,
        ])->assertOk()->assertSee('em 470,0');

        WardServer::actingAs($this->user)->tool(UpdateDesignLayoutTool::class, [
            'design' => $design->uuid,
            'boards' => [['name' => 'Checkout', 'x' => 0, 'y' => 1004]],
            'notes' => [['key' => 'fluxo', 'kind' => 'title', 'text' => 'Fluxo', 'x' => 0, 'y' => -220]],
        ])->assertOk()->assertSee('Layout atualizado');

        WardServer::actingAs($this->user)->tool(GetDesignTool::class, ['design' => $design->uuid])
            ->assertOk()
            ->assertSee('- Home (fixed, 390×844 em 0,0, rev 1)')
            ->assertSee('- Checkout (fixed, 390×844 em 0,1004, rev 1) interativo')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('canvas.boards.1.y', 1004)
                ->where('canvas.notes.0.text', 'Fluxo')
                ->has('preview_url')
                ->etc());

        WardServer::actingAs($this->user)->tool(GetArtboardSourceTool::class, ['design' => $design->uuid, 'name' => 'Home'])
            ->assertOk()->assertSee('<p>home</p>');

        WardServer::actingAs($this->user)->tool(SaveDesignVersionTool::class, ['design' => $design->uuid, 'label' => 'Rodada 1'])
            ->assertOk()->assertSee('Versão 1 salva com 2 artboard(s)');

        WardServer::actingAs($this->user)->tool(DeleteArtboardTool::class, ['design' => $design->uuid, 'name' => 'Checkout'])
            ->assertOk();

        $this->assertSame(['Home'], $design->boards()->pluck('name')->all());
    }

    public function test_errors_come_back_to_the_ai(): void
    {
        $design = $this->createDesign();

        WardServer::actingAs($this->user)->tool(UpsertArtboardTool::class, ['design' => $design->uuid, 'name' => 'Home', 'html' => 'sem tags', 'w' => 390, 'h' => 844])
            ->assertHasErrors(['O conteúdo do artboard não parece ser HTML.']);

        WardServer::actingAs($this->user)->tool(GetArtboardSourceTool::class, ['design' => $design->uuid, 'name' => 'Nada'])
            ->assertHasErrors(['Artboard não encontrado: "Nada".']);

        WardServer::actingAs($this->user)->tool(GetDesignTool::class, ['design' => str_repeat('a', 32)])
            ->assertHasErrors(['Design não encontrado.']);
    }

    public function test_only_owner_or_admin_edits_a_shared_design(): void
    {
        $design = $this->createDesign();
        app(SetArtifactVisibility::class)->base($design, Visibility::Users);
        $other = User::factory()->create();

        WardServer::actingAs($other)->tool(GetDesignTool::class, ['design' => $design->uuid])->assertOk();
        WardServer::actingAs($other)->tool(UpsertArtboardTool::class, ['design' => $design->uuid, 'name' => 'X', 'html' => '<p>x</p>', 'w' => 100, 'h' => 100])
            ->assertHasErrors(['Só o dono do design ou um admin pode alterá-lo.']);

        WardServer::actingAs(User::factory()->admin()->create())
            ->tool(UpsertArtboardTool::class, ['design' => $design->uuid, 'name' => 'X', 'html' => '<p>x</p>', 'w' => 100, 'h' => 100])
            ->assertOk();
    }

    public function test_private_design_is_invisible_to_others(): void
    {
        $design = $this->createDesign();

        WardServer::actingAs(User::factory()->create())->tool(GetDesignTool::class, ['design' => $design->uuid])
            ->assertHasErrors(['Design não encontrado.']);
    }

    public function test_page_and_design_tools_do_not_mix(): void
    {
        $design = $this->createDesign();
        $page = app(PublishArtifact::class)->handle($this->user, 'Página', 'Onboarding', $this->htmlFile());

        WardServer::actingAs($this->user)->tool(GetArtifactSourceTool::class, ['artifact' => $design->uuid])
            ->assertHasErrors(['Este artifact é um design: use get_design e get_artboard_source.']);
        WardServer::actingAs($this->user)->tool(UpdateArtifactTool::class, ['artifact' => $design->uuid, 'html' => '<p>x</p>'])
            ->assertHasErrors(['Este artifact é um design: altere os artboards com upsert_artboard.']);
        WardServer::actingAs($this->user)->tool(GetDesignTool::class, ['design' => $page->uuid])
            ->assertHasErrors();

        WardServer::actingAs($this->user)->tool(ListArtifactsTool::class, ['type' => 'design'])
            ->assertOk()->assertSee('App mobile (Onboarding, design')->assertDontSee('Página');
    }
}
