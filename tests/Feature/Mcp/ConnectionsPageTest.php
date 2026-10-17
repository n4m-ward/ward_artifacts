<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ConnectionsPageTest extends TestCase
{
    use RefreshDatabase;

    private function connect(User $user, string $clientName): string
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($clientName, ['http://localhost/cb'], false);

        $tokenId = Str::random(80);
        DB::table('oauth_access_tokens')->insert([
            'id' => $tokenId,
            'user_id' => $user->id,
            'client_id' => $client->id,
            'scopes' => '["mcp:use"]',
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addDay(),
            'last_used_at' => now(),
        ]);
        DB::table('oauth_refresh_tokens')->insert([
            'id' => Str::random(80),
            'access_token_id' => $tokenId,
            'revoked' => false,
            'expires_at' => now()->addDays(60),
        ]);

        return $client->id;
    }

    public function test_page_shows_connection_instructions(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/connections')
            ->assertOk()
            ->assertSee('http://localhost/mcp')
            ->assertSee('claude mcp add --transport http ward-artifacts');
    }

    public function test_user_sees_and_revokes_their_connections(): void
    {
        $user = User::factory()->create();
        $clientId = $this->connect($user, 'Meu Claude no notebook');

        Volt::actingAs($user)->test('connections.index')
            ->assertSee('Meu Claude no notebook')
            ->call('revoke', $clientId, null)
            ->assertDontSee('Meu Claude no notebook');

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('client_id', $clientId)->value('revoked'));
        $this->assertTrue((bool) DB::table('oauth_refresh_tokens')->value('revoked'));
    }

    public function test_players_only_see_their_own_connections(): void
    {
        $other = User::factory()->create();
        $clientId = $this->connect($other, 'Codex do outro');
        $player = User::factory()->create();

        Volt::actingAs($player)->test('connections.index')
            ->assertDontSee('Codex do outro')
            ->call('revoke', $clientId, $other->id)
            ->assertForbidden();

        $this->assertFalse((bool) DB::table('oauth_access_tokens')->value('revoked'));
    }

    public function test_admin_sees_and_revokes_everyone(): void
    {
        $other = User::factory()->create(['username' => 'bia']);
        $clientId = $this->connect($other, 'OpenCode');

        Volt::actingAs(User::factory()->admin()->create())->test('connections.index')
            ->assertSee('@bia · OpenCode')
            ->call('revoke', $clientId, $other->id);

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->value('revoked'));
    }

    public function test_personal_token_is_shown_once_and_can_be_revoked(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)->test('connections.index')
            ->set('tokenName', 'notebook')
            ->call('createToken')
            ->assertHasNoErrors()
            ->assertSee('notebook');

        $this->assertNotEmpty($component->get('newToken'));

        $tokenId = DB::table('oauth_access_tokens')->where('name', 'notebook')->value('id');
        $component->call('revokeToken', $tokenId);

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', $tokenId)->value('revoked'));
    }

    public function test_deleting_a_user_revokes_their_tokens(): void
    {
        $user = User::factory()->create();
        $this->connect($user, 'Claude Code');

        $user->delete();

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->value('revoked'));
    }
}
