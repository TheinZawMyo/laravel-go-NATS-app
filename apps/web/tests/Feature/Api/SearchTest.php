<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\GroupService;
use App\Services\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_search_only_returns_authorized_conversations(): void
    {
        $alice = User::factory()->create(['username' => 'alice']);
        $bob = User::factory()->create(['username' => 'bob']);
        $mallory = User::factory()->create(['username' => 'mallory']);

        $conversation = app(ConversationService::class)->openDirect($alice, $bob);
        $this->say($alice, $conversation, 'The secret launch code');

        $this
            ->actingAs($alice)
            ->getJson('/api/v1/search/messages?q=secret')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'The secret launch code')
            ->assertJsonPath('data.0.conversation.id', $conversation->id);

        $this
            ->actingAs($mallory)
            ->getJson('/api/v1/search/messages?q=secret')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_message_search_excludes_deleted_and_hidden_history(): void
    {
        $owner = User::factory()->create();
        $group = app(GroupService::class)->create($owner, [
            'title' => 'Team',
            'history_visible_to_new_members' => false,
        ]);

        $this->say($owner, $group, 'Old secret plan');

        $joiner = User::factory()->create(['username' => 'joiner']);
        app(GroupService::class)->addMembers($owner, $group->refresh(), [$joiner->id]);

        $this
            ->actingAs($joiner)
            ->getJson('/api/v1/search/messages?q=secret')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->say($owner, $group, 'Visible secret plan');

        $this
            ->actingAs($joiner)
            ->getJson('/api/v1/search/messages?q=secret')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Visible secret plan');
    }

    public function test_message_search_can_be_scoped_to_one_conversation(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $carol = User::factory()->create();

        $first = app(ConversationService::class)->openDirect($alice, $bob);
        $second = app(ConversationService::class)->openDirect($alice, $carol);

        $this->say($alice, $first, 'needle in the first chat');
        $this->say($alice, $second, 'needle in the second chat');

        $this
            ->actingAs($alice)
            ->getJson("/api/v1/search/messages?q=needle&conversation_id={$second->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'needle in the second chat');
    }

    public function test_conversation_search_matches_titles_and_participants(): void
    {
        $owner = User::factory()->create(['username' => 'owner']);
        $alice = User::factory()->create(['username' => 'alice_wonder', 'name' => 'Alice Wonder']);
        $bob = User::factory()->create(['username' => 'bob']);

        $group = app(GroupService::class)->create($owner, ['title' => 'Weekend Plans']);

        app(ConversationService::class)->openDirect($owner, $alice);
        app(ConversationService::class)->openDirect($alice, $bob);

        $this
            ->actingAs($owner)
            ->getJson('/api/v1/search/conversations?q=weekend')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $group->id);

        $this
            ->actingAs($owner)
            ->getJson('/api/v1/search/conversations?q=alice')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.participant.username', 'alice_wonder');

        $this
            ->actingAs($bob)
            ->getJson('/api/v1/search/conversations?q=weekend')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_search_requires_a_query(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->getJson('/api/v1/search/messages')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');

        $this
            ->actingAs($user)
            ->getJson('/api/v1/search/conversations')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    private function say(User $sender, Conversation $conversation, string $body): void
    {
        app(MessageService::class)->send($sender, $conversation, [
            'client_message_id' => (string) Str::uuid(),
            'body' => $body,
        ]);
    }
}
