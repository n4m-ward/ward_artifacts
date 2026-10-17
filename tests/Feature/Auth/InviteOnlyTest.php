<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InviteOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_seeder_creates_the_first_admin(): void
    {
        config([
            'ward.admin.email' => 'first@ward.test',
            'ward.admin.username' => 'first-admin',
            'ward.admin.password' => 'secret-password',
        ]);

        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $admin = User::sole();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertSame('first-admin', $admin->username);
        $this->assertTrue($admin->isAdmin());
    }

    public function test_seeder_requires_admin_credentials(): void
    {
        config(['ward.admin.email' => null, 'ward.admin.password' => null]);

        $this->expectException(\RuntimeException::class);
        $this->seed(AdminUserSeeder::class);
    }
}
