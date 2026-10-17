<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class McpHttpTest extends TestCase
{
    use RefreshDatabase;

    private function rpc(string $method, array $params = [], string $host = 'http://localhost')
    {
        return $this->postJson("{$host}/mcp", [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], ['Accept' => 'application/json, text/event-stream']);
    }

    public function test_requires_oauth_and_points_to_the_metadata(): void
    {
        $this->rpc('tools/list')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"');

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('registration_endpoint', 'http://localhost/oauth/register')
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_dynamic_client_registration(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Claude Code',
            'redirect_uris' => ['http://localhost:33418/callback'],
        ])
            ->assertCreated()
            ->assertJsonPath('token_endpoint_auth_method', 'none');
    }

    public function test_authenticated_client_lists_tools_and_usage_is_tracked(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ])->assertOk()->assertJsonPath('result.serverInfo.name', 'Ward Artifacts');

        $names = collect($this->rpc('tools/list')->assertOk()->json('result.tools'))->pluck('name');

        $this->assertContains('publish_artifact', $names);
        $this->assertContains('get_template', $names);
        $this->assertNotContains('invite_user', $names);
    }

    public function test_mcp_does_not_answer_on_the_content_host(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $this->rpc('tools/list', host: 'http://content.localhost')->assertNotFound();
        $this->getJson('http://content.localhost/.well-known/oauth-authorization-server')->assertNotFound();
    }

    public function test_discovery_is_not_starved_by_the_registration_limit(): void
    {
        foreach (range(1, 30) as $attempt) {
            $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
            $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk();
        }

        $register = fn () => $this->postJson('/oauth/register', [
            'client_name' => 'Teste', 'redirect_uris' => ['http://127.0.0.1:3000/callback'],
        ]);

        foreach (range(1, 20) as $attempt) {
            $this->assertNotSame(429, $register()->status());
        }

        $register()->assertTooManyRequests();
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
    }
}
