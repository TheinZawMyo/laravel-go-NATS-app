<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\User;
use App\Services\GroupService;
use App\Services\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_members_see_previous_messages_when_history_is_shared(): void
    {
        $owner = User::factory()->create();
        $group = app(GroupService::class)->create($owner, ['title' => 'Team']);

        $this->say($owner, $group, 'First');
        $this->say($owner, $group, 'Second');

        $joiner = User::factory()->create(['username' => 'joiner']);
        app(GroupService::class)->addMembers($owner, $group, [$joiner->id]);

        $this
            ->actingAs($joiner)
            ->getJson("/api/v1/conversations/{$group->id}/messages")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.body', 'First')
            ->assertJsonPath('data.1.body', 'Second');
    }

    public function test_history_hidden_from_new_members_starts_at_their_join_point(): void
    {
        $owner = User::factory()->create();
        $group = app(GroupService::class)->create($owner, [
            'title' => 'Team',
            'history_visible_to_new_members' => false,
        ]);

        $this->say($owner, $group, 'Secret one');
        $this->say($owner, $group, 'Secret two');

        $joiner = User::factory()->create(['username' => 'joiner']);
        app(GroupService::class)->addMembers($owner, $group->refresh(), [$joiner->id]);
        $this->say($owner, $group, 'After joining');

        $this
            ->actingAs($joiner)
            ->getJson("/api/v1/conversations/{$group->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'After joining')
            ->assertJsonPath('data.0.seq', 3);

        // Only the post-join message counts as unread; the hidden ones do not.
        $this
            ->actingAs($joiner)
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 1);
    }

    public function test_existing_members_keep_their_full_history(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['username' => 'alice']);

        $group = app(GroupService::class)->create($owner, [
            'title' => 'Team',
            'member_ids' => [$member->id],
            'history_visible_to_new_members' => false,
        ]);

        $this->say($owner, $group, 'Before');

        $this
            ->actingAs($member)
            ->getJson("/api/v1/conversations/{$group->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Before');

        $this
            ->actingAs($member)
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 1);
    }

    public function test_invite_joiners_respect_the_history_setting(): void
    {
        $owner = User::factory()->create();
        $group = app(GroupService::class)->create($owner, [
            'title' => 'Team',
            'history_visible_to_new_members' => false,
        ]);

        $this->say($owner, $group, 'Old news');

        $invite = app(GroupService::class)->createInvite($owner, $group);
        $joiner = User::factory()->create();

        $this
            ->actingAs($joiner)
            ->postJson("/api/v1/invites/{$invite->token}/join")
            ->assertOk();

        $this
            ->actingAs($joiner)
            ->getJson("/api/v1/conversations/{$group->id}/messages")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function say(User $sender, Conversation $conversation, string $body): void
    {
        app(MessageService::class)->send($sender, $conversation, [
            'client_message_id' => (string) Str::uuid(),
            'body' => $body,
        ]);
    }
}
