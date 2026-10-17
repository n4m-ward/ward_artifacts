<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = config('ward.admin');

        if (blank($admin['email']) || blank($admin['password'])) {
            throw new RuntimeException('Defina WARD_ADMIN_EMAIL e WARD_ADMIN_PASSWORD no .env antes de rodar o seeder.');
        }

        $user = User::firstOrCreate(
            ['email' => $admin['email']],
            [
                'name' => $admin['name'],
                'username' => $admin['username'],
                'password' => $admin['password'],
                'role' => Role::Admin,
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info($user->wasRecentlyCreated
            ? "Admin criado: {$user->email}"
            : "Admin já existia: {$user->email}");
    }
}
