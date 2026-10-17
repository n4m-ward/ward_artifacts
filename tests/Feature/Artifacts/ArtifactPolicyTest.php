<?php

namespace Tests\Feature\Artifacts;

use App\Models\Artifact;
use App\Models\Project;
use App\Models\User;
use Database\Factories\ArtifactFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ArtifactPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function artifact(): ArtifactFactory
    {
        return Artifact::factory()->for($this->owner)->for(Project::factory()->for($this->owner));
    }

    private function canView(?User $user, Artifact $artifact): bool
    {
        return Gate::forUser($user)->allows('view', $artifact);
    }

    public function test_owner_only(): void
    {
        $artifact = $this->artifact()->create();

        $this->assertTrue($this->canView($this->owner, $artifact));
        $this->assertTrue($this->canView($this->admin, $artifact));
        $this->assertFalse($this->canView($this->other, $artifact));
        $this->assertFalse($this->canView(null, $artifact));
    }

    public function test_logged_in_users(): void
    {
        $artifact = $this->artifact()->visibleToUsers()->create();

        $this->assertTrue($this->canView($this->other, $artifact));
        $this->assertFalse($this->canView(null, $artifact));
    }

    public function test_temporary_public(): void
    {
        $artifact = $this->artifact()->publicFor(2)->create();

        $this->assertTrue($this->canView(null, $artifact));
        $this->assertTrue($this->canView($this->other, $artifact));
    }

    public function test_expired_public_falls_back_to_base_visibility(): void
    {
        $artifact = $this->artifact()->publicExpired()->create();

        $this->assertFalse($this->canView(null, $artifact));
        $this->assertFalse($this->canView($this->other, $artifact));
        $this->assertTrue($this->canView($this->owner, $artifact));
    }

    public function test_only_owner_and_admin_manage(): void
    {
        $artifact = $this->artifact()->visibleToUsers()->publicFor(1)->create();

        foreach (['update', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($this->owner)->allows($ability, $artifact));
            $this->assertTrue(Gate::forUser($this->admin)->allows($ability, $artifact));
            $this->assertFalse(Gate::forUser($this->other)->allows($ability, $artifact));
        }
    }
}
