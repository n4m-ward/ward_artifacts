<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_pages_are_in_portuguese(): void
    {
        $this->get('/login')->assertOk()->assertSee('Entrar no Ward Artifacts')->assertSee('Esqueci minha senha');
        $this->get('/forgot-password')->assertOk()->assertSee('Enviar link de redefinição');

        $this->actingAs(User::factory()->create())
            ->get('/settings/password')
            ->assertOk()
            ->assertSee('Trocar senha')
            ->assertSee('Senha atual');
    }

    public function test_reset_email_is_in_portuguese(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        Volt::test('auth.forgot-password')->set('email', $user->email)->call('sendPasswordResetLink');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);

            $this->assertSame('Notificação para redefinir a senha', $mail->subject);
            $this->assertSame('Redefinir Senha', $mail->actionText);

            return true;
        });
    }

    public function test_admin_sends_a_password_reset_link(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $link = Volt::actingAs($admin)->test('users.index')
            ->call('sendPasswordReset', $user->id)
            ->assertSee("Link de redefinição enviado para {$user->email}")
            ->get('sentLink');

        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertStringContainsString('/reset-password/', $link);
    }

    public function test_reset_link_from_admin_resets_the_password(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $link = Volt::actingAs($admin)->test('users.index')->call('sendPasswordReset', $user->id)->get('sentLink');
        auth()->logout();

        Volt::test('auth.reset-password', ['token' => basename(parse_url($link, PHP_URL_PATH))])
            ->set('email', $user->email)
            ->set('password', 'nova-senha-123')
            ->set('password_confirmation', 'nova-senha-123')
            ->call('resetPassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        $this->post('/logout');
        Volt::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'nova-senha-123')
            ->call('login')
            ->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_only_admins_send_reset_links_and_not_to_pending_invites(): void
    {
        Notification::fake();
        $player = User::factory()->create();
        $other = User::factory()->create();

        Volt::actingAs($player)->test('users.index')->assertForbidden();

        $admin = User::factory()->admin()->create();
        $pending = User::factory()->unverified()->create();

        Volt::actingAs($admin)->test('users.index')->call('sendPasswordReset', $pending->id)->assertStatus(422);
        Notification::assertNothingSentTo($pending);
        Notification::assertNothingSentTo($other);
    }

    public function test_changing_email_does_not_turn_the_account_into_a_pending_invite(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('settings.profile')
            ->set('name', $user->name)
            ->set('email', 'novo@ward.test')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertFalse($user->fresh()->hasPendingInvite());
        $this->assertSame('novo@ward.test', $user->fresh()->email);
    }

    public function test_the_last_admin_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->admin()->create();

        Volt::actingAs($admin)->test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors('password');

        $this->assertModelExists($admin);

        User::factory()->create(['role' => Role::Admin]);

        Volt::actingAs($admin)->test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors();

        $this->assertModelMissing($admin);
    }
}
