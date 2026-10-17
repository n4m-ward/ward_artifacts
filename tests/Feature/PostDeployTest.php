<?php

namespace Tests\Feature;

use App\Console\Commands\PostDeploy;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostDeployTest extends TestCase
{
    use RefreshDatabase;

    private string $appliedFile;

    public static ?string $revision = 'abc123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->appliedFile = storage_path('app/deploy-revision');
        File::delete($this->appliedFile);
        static::$revision = 'abc123';
        config(['ward.post_deploy' => true]);

        // Lê a "revisão" de uma propriedade em vez do .git do repositório.
        $this->app->bind(PostDeploy::class, fn () => new class extends PostDeploy
        {
            protected function currentRevision(): ?string
            {
                return PostDeployTest::$revision;
            }
        });
    }

    protected function tearDown(): void
    {
        File::delete($this->appliedFile);

        parent::tearDown();
    }

    public function test_is_disabled_outside_production(): void
    {
        config(['ward.post_deploy' => false]);

        $this->artisan('ward:post-deploy')->doesntExpectOutputToContain('Aplicando')->assertSuccessful();
        $this->assertFileDoesNotExist($this->appliedFile);
    }

    public function test_applies_each_new_commit_once(): void
    {
        $this->artisan('ward:post-deploy')->expectsOutputToContain('Aplicando o deploy abc123')->assertSuccessful();
        $this->assertSame('abc123', File::get($this->appliedFile));

        $this->artisan('ward:post-deploy')->doesntExpectOutputToContain('Aplicando')->assertSuccessful();

        static::$revision = 'def456';
        $this->artisan('ward:post-deploy')->expectsOutputToContain('Aplicando o deploy def456')->assertSuccessful();
    }

    public function test_works_before_the_cache_table_exists(): void
    {
        // Primeiro deploy: CACHE_STORE=database e a tabela cache ainda não existe.
        config(['cache.default' => 'database']);
        Schema::drop('cache');

        $this->artisan('ward:post-deploy')->assertSuccessful();
        $this->assertSame('abc123', File::get($this->appliedFile));
    }

    public function test_fails_when_the_commit_cannot_be_read(): void
    {
        static::$revision = null;

        $this->artisan('ward:post-deploy')->assertFailed();
    }

    public function test_seeds_the_first_admin_on_an_empty_database(): void
    {
        config([
            'ward.admin.email' => 'admin@wardtecnology.com',
            'ward.admin.username' => 'admin',
            'ward.admin.password' => 'senha-forte-de-producao',
        ]);

        $this->artisan('ward:post-deploy')->assertSuccessful();

        $this->assertSame(Role::Admin, User::sole()->role);
    }

    public function test_does_not_seed_when_users_already_exist(): void
    {
        User::factory()->create();
        config(['ward.admin.email' => 'admin@wardtecnology.com', 'ward.admin.password' => 'x']);

        $this->artisan('ward:post-deploy')->assertSuccessful();

        $this->assertSame(1, User::count());
    }

    public function test_reads_the_commit_from_git(): void
    {
        $command = new class extends PostDeploy
        {
            public function revision(): ?string
            {
                return $this->currentRevision();
            }
        };

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $command->revision());
    }

    public function test_root_htaccess_keeps_everything_inside_public(): void
    {
        $htaccess = File::get(base_path('.htaccess'));

        $this->assertStringContainsString('RewriteRule (.*) public/$1 [L]', $htaccess);
        $this->assertStringContainsString('Require all denied', $htaccess);
    }
}
