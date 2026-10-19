<?php

namespace Tests\Feature\Designs;

use App\Actions\Designs\CreateDesign;
use App\Actions\Designs\UpsertArtboard;
use App\Mcp\Servers\WardServer;
use App\Mcp\Tools\GetCommentImageTool;
use App\Mcp\Tools\GetDesignTool;
use App\Models\Artifact;
use App\Models\DesignCommentImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Imagens nos comentários do canvas: enviar antes de publicar, anexar ao publicar,
 * servir por link assinado e a IA ver pelo get_comment_image.
 */
class DesignCommentImagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Artifact $design;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        $this->owner = User::factory()->create(['username' => 'dono']);
        $this->design = app(CreateDesign::class)->handle($this->owner, 'Loja', 'App');
        app(UpsertArtboard::class)->handle($this->design, $this->owner, ['name' => 'Home', 'w' => 390, 'h' => 844, 'html' => '<!doctype html><p>Oi</p>']);
    }

    private function png(int $w = 8, int $h = 6): UploadedFile
    {
        $image = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($image);

        return UploadedFile::fake()->createWithContent('print.png', (string) ob_get_clean());
    }

    private function upload(?User $user = null): array
    {
        return $this->actingAs($user ?? $this->owner)
            ->post(route('designs.comment-images.store', $this->design->uuid), ['file' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk()->json('image');
    }

    public function test_upload_publish_and_view_signed_image(): void
    {
        $image = $this->upload();
        $this->assertSame([8, 6], [$image['width'], $image['height']]);

        $state = $this->actingAs($this->owner)->postJson(route('designs.comments.store', $this->design->uuid), [
            'body' => '', 'images' => [$image['id']], 'board' => $this->design->boards()->value('id'),
        ])->assertOk()->json('state');

        $message = $state['comments'][0]['messages'][0];
        $this->assertSame('', $message['body']);
        $this->assertCount(1, $message['images']);

        // Link assinado abre sem sessão; sem assinatura, não.
        auth()->logout();
        $this->get($message['images'][0]['url'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $name = DesignCommentImage::sole()->name;
        $this->get("/d/{$this->design->uuid}/comment-images/{$name}")->assertForbidden();
    }

    public function test_only_own_pending_images_attach_and_deleting_the_comment_removes_files(): void
    {
        $other = User::factory()->create();
        $this->design->forceFill(['visibility' => 'users'])->save();
        $theirs = $this->upload($other);

        $this->actingAs($this->owner)->postJson(route('designs.comments.store', $this->design->uuid), [
            'body' => 'Olha isso', 'images' => [$theirs['id']], 'x' => 10, 'y' => 10,
        ])->assertStatus(422);

        $mine = $this->upload();
        $thread = $this->actingAs($this->owner)->postJson(route('designs.comments.store', $this->design->uuid), [
            'body' => 'Olha isso', 'images' => [$mine['id']], 'x' => 10, 'y' => 10,
        ])->json('thread');

        $image = DesignCommentImage::where('comment_id', $thread)->sole();
        Storage::disk('artifacts')->assertExists($image->storagePath());

        $this->actingAs($this->owner)->deleteJson(route('designs.comments.destroy', [$this->design->uuid, $thread]))->assertOk();
        Storage::disk('artifacts')->assertMissing($image->storagePath());
        $this->assertSame(0, DesignCommentImage::where('comment_id', $thread)->count());

        // Tirar uma enviada antes de publicar.
        $pending = $this->upload();
        $this->actingAs($this->owner)->deleteJson(route('designs.comment-images.destroy', [$this->design->uuid, $pending['id']]))->assertOk();
        $this->assertNull(DesignCommentImage::find($pending['id']));
    }

    public function test_ai_sees_the_count_and_the_image(): void
    {
        $image = $this->upload();
        $this->actingAs($this->owner)->postJson(route('designs.comments.store', $this->design->uuid), [
            'body' => 'Deixa igual a este print', 'images' => [$image['id']], 'x' => 1, 'y' => 1,
        ])->assertOk();

        WardServer::actingAs($this->owner)->tool(GetDesignTool::class, ['design' => $this->design->uuid])
            ->assertSee('[1 imagem(ns) anexada(s)]')
            ->assertSee('get_comment_image(design, 1, 1…1)');

        WardServer::actingAs($this->owner)->tool(GetCommentImageTool::class, ['design' => $this->design->uuid, 'comment' => 1])
            ->assertOk()->assertSee('Imagem 1 de 1 do comentário #1');

        WardServer::actingAs($this->owner)->tool(GetCommentImageTool::class, ['design' => $this->design->uuid, 'comment' => 1, 'image' => 2])
            ->assertHasErrors(['tem 1 imagem(ns)']);
    }
}
