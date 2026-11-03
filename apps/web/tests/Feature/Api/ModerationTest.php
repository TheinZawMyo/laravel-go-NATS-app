<?php

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrators_can_suspend_users_and_revoke_their_devices(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['username' => 'spammer']);
        $device = UserDevice::factory()->for($target)->create();

        $this
            ->actingAs($admin)
            ->postJson('/api/v1/admin/users/spammer/moderate', [
                'action' => 'suspend',
                'reason' => 'Repeated spam',
            ])
            ->assertOk()
            ->assertJsonPath('data.username', 'spammer');

        $this->assertSame(AccountStatus::Suspended, $target->refresh()->status);
        $this->assertNotNull($device->refresh()->revoked_at);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.suspended',
            'subject_id' => $target->id,
        ]);
    }

    public function test_administrators_can_ban_and_reactivate_users(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['username' => 'banned']);

        $this
            ->actingAs($admin)
            ->postJson('/api/v1/admin/users/banned/moderate', ['action' => 'ban'])
            ->assertOk();

        $this->assertSame(AccountStatus::Banned, $target->refresh()->status);

        $this
            ->actingAs($admin)
            ->postJson('/api/v1/admin/users/banned/moderate', ['action' => 'reactivate'])
            ->assertOk();

        $this->assertSame(AccountStatus::Active, $target->refresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.reactivated',
            'subject_id' => $target->id,
        ]);
    }

    public function test_non_administrators_cannot_moderate_users(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create(['username' => 'target']);

        $this
            ->actingAs($user)
            ->postJson('/api/v1/admin/users/target/moderate', ['action' => 'suspend'])
            ->assertForbidden();

        $this->assertSame(AccountStatus::Active, $target->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_administrators_cannot_moderate_themselves_or_other_administrators(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'admin']);
        $other = User::factory()->admin()->create(['username' => 'other_admin']);

        $this
            ->actingAs($admin)
            ->postJson('/api/v1/admin/users/admin/moderate', ['action' => 'suspend'])
            ->assertUnprocessable();

        $this
            ->actingAs($admin)
            ->postJson('/api/v1/admin/users/other_admin/moderate', ['action' => 'suspend'])
            ->assertUnprocessable();

        $this->assertSame(AccountStatus::Active, $admin->refresh()->status);
        $this->assertSame(AccountStatus::Active, $other->refresh()->status);
    }

    public function test_administrators_can_read_the_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['username' => 'target']);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/users/target/moderate', ['action' => 'suspend']);

        $this
            ->actingAs($admin)
            ->getJson('/api/v1/admin/audit-logs?action=user.suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'user.suspended')
            ->assertJsonPath('data.0.actor.id', $admin->id);
    }
}
