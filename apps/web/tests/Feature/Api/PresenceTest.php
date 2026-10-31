<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\ConversationService;
use App\Services\PresenceStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_online_member_is_reported_in_the_conversation_list(): void
    {
        $viewer = User::factory()->create();
        $other = User::factory()->create(['username' => 'bob']);

        app(ConversationService::class)->openDirect($viewer, $other);

        $this->fakePresence([
            $other->id => ['online' => true, 'last_seen_at' => '2026-10-31T10:00:00+00:00'],
        ]);

        $this
            ->actingAs($viewer)
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.participant.online', true)
            ->assertJsonPath('data.0.participant.last_seen_at', '2026-10-31T10:00:00+00:00');
    }

    public function test_users_without_a_lease_are_offline_and_fall_back_to_the_column(): void
    {
        $viewer = User::factory()->create();
        $other = User::factory()->create([
            'username' => 'bob',
            'last_seen_at' => '2026-10-30T08:30:00+00:00',
        ]);

        $conversation = app(ConversationService::class)->openDirect($viewer, $other);

        $this->fakePresence([]);

        $this
            ->actingAs($viewer)
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.participant.online', false)
            ->assertJsonPath('data.participant.last_seen_at', '2026-10-30T08:30:00.000000Z');
    }

    public function test_user_lookup_reports_presence(): void
    {
        $viewer = User::factory()->create();
        $other = User::factory()->create(['username' => 'bob']);

        $this->fakePresence([
            $other->id => ['online' => true, 'last_seen_at' => '2026-10-31T10:00:00+00:00'],
        ]);

        $this
            ->actingAs($viewer)
            ->getJson('/api/v1/users/bob')
            ->assertOk()
            ->assertJsonPath('data.online', true)
            ->assertJsonPath('data.last_seen_at', '2026-10-31T10:00:00+00:00');
    }

    /**
     * Bind a presence store whose answer we control, so the assertions do not
     * need a running Redis.
     *
     * @param  array<string, array{online: bool, last_seen_at: string|null}>  $presence
     */
    private function fakePresence(array $presence): void
    {
        $this->app->instance(PresenceStore::class, new class($presence) implements PresenceStore
        {
            /**
             * @param  array<string, array{online: bool, last_seen_at: string|null}>  $presence
             */
            public function __construct(private readonly array $presence) {}

            public function snapshot(array $userIds): array
            {
                return array_intersect_key($this->presence, array_flip($userIds));
            }
        });
    }
}
