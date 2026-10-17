<?php

namespace Tests\Feature\Designs;

use App\Actions\Artifacts\CreateArtifactPreview;
use App\Actions\Artifacts\SetArtifactVisibility;
use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\UpdateDesignLayout;
use App\Actions\Designs\UpsertArtboard;
use App\Designs\DesignState;
use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DesignLiveTest extends TestCase
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
        $this->board('Home', 'v1');
    }

    private function board(string $name, string $text): void
    {
        app(UpsertArtboard::class)->handle($this->design, $this->owner, [
            'name' => $name, 'html' => "<!doctype html><p>{$text}</p>", 'w' => 390, 'h' => 844,
        ]);
    }

    private function liveUrl(): string
    {
        return route('designs.live', $this->design->uuid);
    }

    /**
     * URL assinada que a página entrega ao canvas.
     */
    private function pageLiveUrl(): string
    {
        $html = $this->get($this->design->path())->assertOk()->getContent();
        preg_match('#<script type="application/json" id="ward-design-config">(.*?)</script>#s', $html, $match);

        return json_decode($match[1], true)['liveUrl'];
    }

    public function test_returns_204_when_nothing_changed_and_the_state_otherwise(): void
    {
        $this->actingAs($this->owner);
        $revision = $this->design->fresh()->design_revision;

        $this->get($this->liveUrl().'?rev='.$revision)
            ->assertNoContent()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->board('Checkout', 'pagar');
        app(UpdateDesignLayout::class)->handle($this->design, [], [
            ['key' => 'fluxo', 'kind' => 'title', 'text' => 'Fluxo', 'x' => 0, 'y' => -200],
        ]);

        $this->get($this->liveUrl().'?rev='.$revision)
            ->assertOk()
            ->assertJsonPath('revision', $this->design->fresh()->design_revision)
            ->assertJsonCount(2, 'boards')
            ->assertJsonPath('boards.1.name', 'Checkout')
            ->assertJsonPath('notes.0.text', 'Fluxo');
    }

    public function test_board_url_changes_when_its_html_changes(): void
    {
        $this->actingAs($this->owner);
        $before = $this->getJson($this->liveUrl())->json('boards.0');

        $this->board('Home', 'v2');
        $after = $this->getJson($this->liveUrl())->json('boards.0');

        $this->assertSame($before['id'], $after['id']);
        $this->assertSame($before['revision'] + 1, $after['revision']);
        $this->assertNotSame($before['url'], $after['url']);
    }

    public function test_logged_in_users_need_permission_to_view(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)->get($this->liveUrl())->assertForbidden();

        app(SetArtifactVisibility::class)->base($this->design, Visibility::Users);
        $this->actingAs($other)->get($this->liveUrl())->assertOk();
    }

    public function test_guests_need_the_signed_url_from_the_page(): void
    {
        $this->get($this->liveUrl())->assertForbidden();

        app(SetArtifactVisibility::class)->makePublic($this->design, 1);
        $signed = $this->pageLiveUrl();

        $this->get($signed)->assertOk()->assertJsonCount(1, 'boards');

        // O canvas acrescenta ?rev=N à URL assinada.
        $revision = $this->design->fresh()->design_revision;
        $this->get($signed.'&rev='.$revision)->assertNoContent();
        $this->get($signed.'&rev=0')->assertOk();

        // Assinatura adulterada ou de outro design: 403.
        $this->get(preg_replace('/signature=\w+/', 'signature=abc', $signed))->assertForbidden();
        $other = app(CreateDesign::class)->handle($this->owner, 'Outro', 'Onboarding');
        $this->get(str_replace($this->design->uuid, $other->uuid, $signed))->assertForbidden();
    }

    public function test_public_link_live_url_expires_with_the_link(): void
    {
        app(SetArtifactVisibility::class)->makePublic($this->design, 1);
        $signed = $this->pageLiveUrl();

        $this->travel(61)->minutes();

        $this->get($signed)->assertForbidden();
    }

    public function test_preview_page_gets_a_working_live_url(): void
    {
        $url = app(CreateArtifactPreview::class)->handle($this->owner, $this->design);
        $html = $this->get($url)->assertOk()->getContent();
        preg_match('#<script type="application/json" id="ward-design-config">(.*?)</script>#s', $html, $match);
        $live = json_decode($match[1], true)['liveUrl'];

        $this->get($live)->assertOk()->assertJsonPath('boards.0.name', 'Home');
    }

    public function test_page_and_live_share_the_same_state(): void
    {
        $this->actingAs($this->owner);
        $html = $this->get($this->design->path())->getContent();
        preg_match('#<script type="application/json" id="ward-design-config">(.*?)</script>#s', $html, $match);
        $config = json_decode($match[1], true);

        $this->assertSame($this->design->uuid, $config['design']);
        $this->assertSame('http://content.localhost', $config['contentOrigin']);
        $this->assertSame(
            array_column(app(DesignState::class)->toArray($this->design->fresh())['boards'], 'name'),
            array_column($config['state']['boards'], 'name'),
        );
    }

    public function test_unknown_or_non_design_artifacts_are_404(): void
    {
        $this->actingAs($this->owner);

        $this->get(route('designs.live', str_repeat('a', 32)))->assertNotFound();
    }
}
