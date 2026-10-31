<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\ContactService;
use App\Services\ConversationService;
use App\Services\GroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocking_removes_contacts_and_pending_requests(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create(['username' => 'alice']);

        $contacts = app(ContactService::class);
        $request = $contacts->sendRequest($actor, $target);
        $contacts->accept($target, $request);

        $contacts->sendRequest($actor, User::factory()->create(['username' => 'bob']));

        $this
            ->actingAs($actor)
            ->postJson('/api/v1/blocks', ['username' => $target->username, 'reason' => 'Spam'])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $target->id)
            ->assertJsonPath('data.reason', 'Spam');

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseHas('user_blocks', [
            'blocker_id' => $actor->id,
            'blocked_id' => $target->id,
        ]);

        $this
            ->actingAs($actor)
            ->getJson('/api/v1/blocks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.username', 'alice');
    }

    public function test_a_block_prevents_direct_conversations_and_messages(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create(['username' => 'alice']);
        $conversation = app(ConversationService::class)->openDirect($actor, $target);

        // The target is the blocker, so the check must work in both directions.
        app(ContactService::class)->block($target, $actor);

        $this
            ->actingAs($actor)
            ->postJson('/api/v1/conversations/direct', ['username' => 'alice'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => 'Still there?',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_group_messages_are_still_allowed_between_blocked_users(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['username' => 'alice']);

        $group = app(GroupService::class)->create($owner, [
            'title' => 'Team',
            'member_ids' => [$member->id],
        ]);

        app(ContactService::class)->block($member, $owner);

        $this
            ->actingAs($owner)
            ->postJson("/api/v1/conversations/{$group->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => 'Meeting at ten',
            ])
            ->assertCreated()
            ->assertJsonPath('data.seq', 1);
    }

    public function test_unblocking_restores_search_and_direct_conversations(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create(['username' => 'alice']);

        app(ContactService::class)->block($actor, $target);

        $this
            ->actingAs($actor)
            ->deleteJson('/api/v1/blocks/alice')
            ->assertNoContent();

        $this->assertDatabaseCount('user_blocks', 0);

        $this
            ->actingAs($actor)
            ->postJson('/api/v1/conversations/direct', ['username' => 'alice'])
            ->assertCreated();
    }

    public function test_users_cannot_block_themselves(): void
    {
        $actor = User::factory()->create(['username' => 'actor']);

        $this
            ->actingAs($actor)
            ->postJson('/api/v1/blocks', ['username' => 'actor'])
            ->assertForbidden();
    }
}
