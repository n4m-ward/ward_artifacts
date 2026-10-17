<?php

namespace Tests\Feature\Users;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_users_page(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/users')
            ->assertOk()
            ->assertSee('Convidar usuário');
    }

    public function test_player_cannot_open_users_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/users')
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/users')->assertRedirect('/login');
    }

    public function test_admin_can_invite_a_user(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        Volt::actingAs($admin)
            ->test('users.index')
            ->set('name', 'Nam Ward')
            ->set('username', 'nam-ward')
            ->set('email', 'nam@ward.test')
            ->set('role', 'player')
            ->call('invite')
            ->assertHasNoErrors()
            ->assertSet('inviteUrl', fn ($url) => str_contains($url, '/invite/'));

        $invited = User::where('email', 'nam@ward.test')->sole();
        $this->assertSame('nam-ward', $invited->username);
        $this->assertSame(Role::Player, $invited->role);
        $this->assertTrue($invited->hasPendingInvite());
        $this->assertTrue($invited->inviter->is($admin));

        Notification::assertSentTo($invited, UserInvited::class);
    }

    public function test_invite_validates_username(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        User::factory()->create(['username' => 'taken']);

        foreach (['Com Espaço', 'login', 'taken', 'a'] as $username) {
            Volt::actingAs($admin)
                ->test('users.index')
                ->set('name', 'X')
                ->set('username', $username)
                ->set('email', "{$username}@ward.test")
                ->call('invite')
                ->assertHasErrors('username');
        }
    }

    public function test_player_cannot_invite(): void
    {
        $player = User::factory()->create();

        Volt::actingAs($player)->test('users.index')->assertForbidden();
    }

    public function test_invited_user_sets_password_and_logs_in(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $url = Volt::actingAs($admin)
            ->test('users.index')
            ->set('name', 'Nam Ward')
            ->set('username', 'nam-ward')
            ->set('email', 'nam@ward.test')
            ->call('invite')
            ->get('inviteUrl');

        auth()->logout();

        $this->get($url)->assertOk()->assertSee('Aceitar convite');

        $token = basename(parse_url($url, PHP_URL_PATH));

        Livewire::withQueryParams(['email' => 'nam@ward.test'])
            ->test('auth.accept-invite', ['token' => $token])
            ->set('password', 'uma-senha-forte-123')
            ->set('password_confirmation', 'uma-senha-forte-123')
            ->call('acceptInvite')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $invited = User::where('email', 'nam@ward.test')->sole();
        $this->assertFalse($invited->hasPendingInvite());
        $this->assertAuthenticatedAs($invited);
    }

    public function test_invalid_invite_token_is_rejected(): void
    {
        User::factory()->unverified()->create(['email' => 'nam@ward.test']);

        Livewire::withQueryParams(['email' => 'nam@ward.test'])
            ->test('auth.accept-invite', ['token' => 'token-falso'])
            ->set('password', 'uma-senha-forte-123')
            ->set('password_confirmation', 'uma-senha-forte-123')
            ->call('acceptInvite')
            ->assertHasErrors('password');

        $this->assertGuest();
    }

    public function test_admin_can_change_role_but_not_their_own(): void
    {
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create();

        Volt::actingAs($admin)->test('users.index')
            ->call('changeRole', $player->id, 'admin');
        $this->assertSame(Role::Admin, $player->fresh()->role);

        Volt::actingAs($admin)->test('users.index')
            ->call('changeRole', $admin->id, 'player')
            ->assertForbidden();
        $this->assertSame(Role::Admin, $admin->fresh()->role);
    }

    public function test_admin_can_delete_others_but_not_themselves(): void
    {
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create();

        Volt::actingAs($admin)->test('users.index')->call('deleteUser', $player->id);
        $this->assertModelMissing($player);

        Volt::actingAs($admin)->test('users.index')
            ->call('deleteUser', $admin->id)
            ->assertForbidden();
        $this->assertModelExists($admin);
    }

    public function test_resend_invite_issues_a_new_link(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $pending = User::factory()->unverified()->create();

        Volt::actingAs($admin)->test('users.index')
            ->call('resendInvite', $pending->id)
            ->assertSet('inviteUrl', fn ($url) => str_contains($url, '/invite/'));

        Notification::assertSentTo($pending, UserInvited::class);
    }
}
