<?php

namespace Tests\Feature\Api;

use App\Enums\ConversationRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\GroupService;
use App\Services\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MessagePinTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderators_can_pin_and_unpin_messages(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['username' => 'alice']);

        $groups = app(GroupService::class);
        $group = $groups->create($owner, [
            'title' => 'Team',
            'member_ids' => [$member->id],
        ]);
        $groups->changeRole($owner, $group, $member, ConversationRole::Moderator);

        $message = $this->say($owner, $group);

        $this
            ->actingAs($member)
            ->postJson("/api/v1/messages/{$message->id}/pin")
            ->assertCreated()
            ->assertJsonPath('data.message.id', $message->id)
            ->assertJsonPath('data.pinned_by.id', $member->id);

        $this
            ->actingAs($member)
            ->postJson("/api/v1/messages/{$message->id}/pin")
            ->assertCreated();

        $this->assertDatabaseCount('message_pins', 1);

        $this
            ->actingAs($member)
            ->getJson("/api/v1/conversations/{$group->id}/pins")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message.id', $message->id);

        $this
            ->actingAs($member)
            ->deleteJson("/api/v1/messages/{$message->id}/pin")
            ->assertNoContent();

        $this->assertDatabaseCount('message_pins', 0);
    }

    public function test_plain_members_cannot_pin(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['username' => 'alice']);

        $group = app(GroupService::class)->create($owner, [
            'title' => 'Team',
            'member_ids' => [$member->id],
        ]);

        $message = $this->say($owner, $group);

        $this
            ->actingAs($member)
            ->postJson("/api/v1/messages/{$message->id}/pin")
            ->assertForbidden();
    }

    public function test_a_message_can_only_be_pinned_by_viewers_of_its_conversation(): void
    {
        $owner = User::factory()->create();
        $group = app(GroupService::class)->create($owner, ['title' => 'Team']);
        $other = app(GroupService::class)->create($owner, ['title' => 'Other']);

        $message = $this->say($owner, $other);
        $outsider = User::factory()->create();

        $this
            ->actingAs($outsider)
            ->postJson("/api/v1/messages/{$message->id}/pin")
            ->assertForbidden();
    }

    public function test_both_participants_of_a_direct_conversation_can_pin(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create(['username' => 'alice']);

        $conversation = app(ConversationService::class)->openDirect($actor, $other);
        $message = $this->say($actor, $conversation);

        $this
            ->actingAs($other)
            ->postJson("/api/v1/messages/{$message->id}/pin")
            ->assertCreated()
            ->assertJsonPath('data.message.body', 'Hello');
    }

    private function say(User $sender, Conversation $conversation): Message
    {
        [$message] = app(MessageService::class)->send($sender, $conversation, [
            'client_message_id' => (string) Str::uuid(),
            'body' => 'Hello',
        ]);

        return $message;
    }
}
