<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_only_see_their_own_devices()
    {
        $user = User::factory()->create();
        $owned = UserDevice::factory()->for($user)->create();
        UserDevice::factory()->create();

        $response = $this
            ->actingAs($user)
            ->getJson('/api/v1/devices');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $owned->id);
    }

    public function test_users_can_revoke_their_own_devices()
    {
        $user = User::factory()->create();
        $device = UserDevice::factory()->for($user)->create();

        $this
            ->actingAs($user)
            ->deleteJson("/api/v1/devices/{$device->id}")
            ->assertNoContent();

        $this->assertNotNull($device->refresh()->revoked_at);
    }

    public function test_users_cannot_revoke_other_devices()
    {
        $user = User::factory()->create();
        $device = UserDevice::factory()->create();

        $this
            ->actingAs($user)
            ->deleteJson("/api/v1/devices/{$device->id}")
            ->assertForbidden();

        $this->assertNull($device->refresh()->revoked_at);
    }

    public function test_users_can_register_a_device(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->postJson('/api/v1/devices', [
                'name' => 'Chrome on macOS',
                'platform' => 'web',
                'push_token' => 'push-token-1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Chrome on macOS')
            ->assertJsonPath('data.platform', 'web')
            ->assertJsonMissingPath('data.push_token');

        $this->assertDatabaseHas('user_devices', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'push_token' => 'push-token-1',
        ]);
    }

    public function test_registering_the_same_push_token_refreshes_instead_of_duplicating(): void
    {
        $user = User::factory()->create();

        $first = $this
            ->actingAs($user)
            ->postJson('/api/v1/devices', ['push_token' => 'push-token-1', 'platform' => 'web'])
            ->assertCreated();

        $this
            ->actingAs($user)
            ->postJson('/api/v1/devices', ['push_token' => 'push-token-1', 'platform' => 'web', 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.name', 'Renamed');

        $this->assertSame(1, UserDevice::query()->count());
    }

    public function test_a_shared_push_token_moves_to_the_signed_in_user(): void
    {
        $previous = User::factory()->create();
        $user = User::factory()->create();

        $device = UserDevice::factory()->for($previous)->create(['push_token' => 'shared-token']);

        $this
            ->actingAs($user)
            ->postJson('/api/v1/devices', ['push_token' => 'shared-token'])
            ->assertOk()
            ->assertJsonPath('data.id', $device->id);

        $this->assertSame($user->id, $device->refresh()->user_id);
    }

    public function test_registering_a_device_validates_the_platform(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->postJson('/api/v1/devices', ['platform' => 'dreamcast'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('platform');
    }

    public function test_guests_cannot_register_devices(): void
    {
        $this->postJson('/api/v1/devices', ['push_token' => 'push-token-1'])->assertUnauthorized();
    }

    public function test_guests_cannot_access_devices()
    {
        $this->getJson('/api/v1/devices')->assertUnauthorized();
    }
}
