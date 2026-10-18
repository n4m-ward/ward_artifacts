<?php

namespace Tests\Feature\Designs;

use App\Actions\Artifacts\SetArtifactVisibility;
use App\Actions\Designs\AddDesignAsset;
use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\UpsertArtboard;
use App\Artifacts\ContentGateway;
use App\Designs\DesignCanvas;
use App\Enums\Visibility;
use App\Mcp\Servers\WardServer;
use App\Mcp\Tools\AddDesignAssetTool;
use App\Mcp\Tools\GetDesignTool;
use App\Models\Artifact;
use App\Models\DesignBoard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DesignAssetsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Artifact $design;

    private DesignBoard $home;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create(['username' => 'nam-ward']);
        $this->design = app(CreateDesign::class)->handle($this->owner, 'App mobile', 'Onboarding');
        $this->home = app(UpsertArtboard::class)->handle($this->design, $this->owner, [
            'name' => 'Home', 'html' => '<!doctype html><img src="assets/x.png" alt="">', 'w' => 390, 'h' => 844,
        ]);
    }

    private function png(int $width = 40, int $height = 20): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_upload_stores_the_image_by_content_and_lists_it(): void
    {
        $file = UploadedFile::fake()->createWithContent('Foto Hero.png', $this->png());

        $response = $this->actingAs($this->owner)->post(route('designs.assets.store', $this->design->uuid), ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('asset.width', 40)
            ->assertJsonPath('asset.height', 20);

        $name = $response->json('asset.name');
        $this->assertMatchesRegularExpression('/^foto-hero-[0-9a-f]{10}\.png$/', $name);
        $this->assertSame("assets/{$name}", $response->json('asset.path'));
        Storage::disk('artifacts')->assertExists("{$this->design->uuid}/assets/{$name}");

        // Mesmo conteúdo: mesmo arquivo.
        $this->actingAs($this->owner)->post(route('designs.assets.store', $this->design->uuid), ['file' => UploadedFile::fake()->createWithContent('Foto Hero.png', $this->png())], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, $this->design->assets()->count());

        $this->actingAs($this->owner)->get(route('designs.assets.show', [$this->design->uuid, $name]))
            ->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertGreaterThan(0, $this->design->fresh()->size_bytes - strlen('<!doctype html><img src="assets/x.png" alt="">'));
    }

    public function test_only_images_are_accepted_and_only_editors_upload(): void
    {
        $this->actingAs($this->owner)->post(route('designs.assets.store', $this->design->uuid), ['file' => UploadedFile::fake()->createWithContent('a.php', '<?php echo 1;')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Formato não aceito. Use PNG, JPG, WebP, GIF, AVIF ou SVG.');

        $viewer = User::factory()->create();
        app(SetArtifactVisibility::class)->base($this->design, Visibility::Users);
        $this->actingAs($viewer)->post(route('designs.assets.store', $this->design->uuid), ['file' => UploadedFile::fake()->createWithContent('a.png', $this->png())], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($viewer)->getJson(route('designs.assets.index', $this->design->uuid))->assertOk();
    }

    public function test_boards_load_images_relative_to_their_url_and_zip_carries_them(): void
    {
        $asset = app(AddDesignAsset::class)->handle($this->design, $this->owner, $this->png(), 'x.png');
        $boardUrl = app(ContentGateway::class)->boardUrl($this->home->fresh());
        $assetUrl = preg_replace('#/\d+\.html$#', '/'.$asset->path(), $boardUrl);

        $this->get($assetUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(preg_replace('#/\d+\.html$#', '/assets/../../etc.png', $boardUrl))->assertNotFound();
        $this->get(preg_replace('#/\d+\.html$#', '/assets/nao-existe-0000000000.png', $boardUrl))->assertNotFound();

        $zip = tempnam(sys_get_temp_dir(), 'ward');
        app(DesignCanvas::class)->writeZip($this->design, $zip);
        $archive = new ZipArchive;
        $archive->open($zip);
        $this->assertNotFalse($archive->locateName("boards/assets/{$asset->name}"));
        $archive->close();
        @unlink($zip);
    }

    public function test_ai_can_add_images_and_sees_them_in_get_design(): void
    {
        WardServer::actingAs($this->owner)->tool(AddDesignAssetTool::class, [
            'design' => $this->design->uuid, 'filename' => 'logo.png', 'data' => base64_encode($this->png(64, 64)),
        ])->assertOk()->assertSee('use "assets/logo-');

        WardServer::actingAs($this->owner)->tool(GetDesignTool::class, ['design' => $this->design->uuid])
            ->assertSee('Imagens do design (use no HTML como "assets/{nome}"): logo-');

        WardServer::actingAs($this->owner)->tool(AddDesignAssetTool::class, ['design' => $this->design->uuid, 'filename' => 'x.png', 'data' => '***'])
            ->assertHasErrors(['data não é base64 válido.']);
    }

    public function test_print_page_puts_each_board_on_its_own_page(): void
    {
        app(UpsertArtboard::class)->handle($this->design, $this->owner, [
            'name' => 'Post', 'html' => '<!doctype html><p>post</p>', 'w' => 1080, 'h' => 1080,
        ]);

        $this->actingAs($this->owner)->get(route('designs.print', $this->design->uuid))
            ->assertOk()
            ->assertSee('@page b'.$this->home->id.' { size: 390px 844px; margin: 0; }', false)
            ->assertSee('size: 1080px 1080px', false);

        $this->actingAs($this->owner)->get(route('designs.print', $this->design->uuid).'?boards='.$this->home->id)
            ->assertOk()->assertDontSee('1080px 1080px', false);

        $this->actingAs(User::factory()->create())->get(route('designs.print', $this->design->uuid))->assertForbidden();
    }
}
