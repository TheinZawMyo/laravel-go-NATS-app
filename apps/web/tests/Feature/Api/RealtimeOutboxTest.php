<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealtimeOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_a_message_queues_a_message_created_event(): void
    {
        [$actor, , $conversation] = $this->directConversation();

        $response = $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => 'Hello realtime',
            ])
            ->assertCreated();

        $event = OutboxEvent::query()->sole();

        $this->assertSame('message.created', $event->event_type);
        $this->assertSame('conversation', $event->aggregate_type);
        $this->assertSame($conversation->id, $event->aggregate_id);
        $this->assertSame(0, $event->attempts);
        $this->assertNull($event->published_at);
        $this->assertNull($event->last_error);

        $payload = $event->payload;

        $this->assertSame(1, $payload['version']);
        $this->assertSame('message.created', $payload['type']);
        $this->assertSame($conversation->id, $payload['conversation_id']);
        $this->assertSame(1, $payload['seq']);
        $this->assertIsString($payload['event_id']);
        $this->assertIsString($payload['occurred_at']);
        $this->assertSame($response->json('data.id'), $payload['data']['id']);
        $this->assertSame('Hello realtime', $payload['data']['body']);
        $this->assertSame($actor->id, $payload['data']['sender']['id']);
        $this->assertNull($payload['data']['reply_to']);
    }

    public function test_replying_carries_the_replied_to_message_in_the_event(): void
    {
        [$actor, $other, $conversation] = $this->directConversation();

        $original = $this->sendMessage($actor, $conversation, 'Original');

        $this->actingAs($other)->postJson(
            "/api/v1/conversations/{$conversation->id}/messages",
            [
                'client_message_id' => (string) Str::uuid(),
                'body' => 'Reply',
                'reply_to_message_id' => $original->id,
            ],
        )->assertCreated();

        $payload = OutboxEvent::query()
            ->where('event_type', 'message.created')
            ->orderByDesc('created_at')
            ->firstOrFail()
            ->payload;

        $this->assertSame($original->id, $payload['data']['reply_to']['id']);
        $this->assertSame('Original', $payload['data']['reply_to']['body']);
    }

    public function test_editing_a_message_queues_a_message_updated_event(): void
    {
        [$actor, , $conversation] = $this->directConversation();

        $message = $this->sendMessage($actor, $conversation, 'Before');

        $this
            ->actingAs($actor)
            ->patchJson("/api/v1/messages/{$message->id}", ['body' => 'After'])
            ->assertOk();

        $payload = OutboxEvent::query()
            ->where('event_type', 'message.updated')
            ->sole()
            ->payload;

        $this->assertSame($message->id, $payload['data']['id']);
        $this->assertSame('After', $payload['data']['body']);
        $this->assertNotNull($payload['data']['edited_at']);
        $this->assertSame($conversation->id, $payload['conversation_id']);
    }

    public function test_deleting_a_message_queues_a_message_deleted_event(): void
    {
        [$actor, , $conversation] = $this->directConversation();

        $message = $this->sendMessage($actor, $conversation, 'Regrettable');

        $this
            ->actingAs($actor)
            ->deleteJson("/api/v1/messages/{$message->id}")
            ->assertOk();

        $payload = OutboxEvent::query()
            ->where('event_type', 'message.deleted')
            ->sole()
            ->payload;

        $this->assertSame($message->id, $payload['data']['id']);
        $this->assertNull($payload['data']['body']);
        $this->assertNotNull($payload['data']['deleted_at']);
    }

    public function test_events_are_only_written_for_committed_changes(): void
    {
        [$actor, $other, $conversation] = $this->directConversation();

        $this->sendMessage($actor, $conversation, 'Only once');

        $this->assertSame(1, OutboxEvent::query()->count());

        // Idempotent replay: same client message id, nothing new committed.
        $this->actingAs($actor)->postJson(
            "/api/v1/conversations/{$conversation->id}/messages",
            [
                'client_message_id' => Message::query()->sole()->client_message_id,
                'body' => 'Only once',
            ],
        )->assertOk();

        // Rejected write: validation error and no event.
        $this
            ->actingAs($other)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => 'not-a-uuid',
                'body' => 'Invalid',
            ])
            ->assertUnprocessable();

        $this->assertSame(1, OutboxEvent::query()->count());
    }

    /**
     * @return array{0: User, 1: User, 2: Conversation}
     */
    private function directConversation(): array
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();

        return [
            $actor,
            $other,
            app(ConversationService::class)->openDirect($actor, $other),
        ];
    }

    private function sendMessage(User $sender, Conversation $conversation, string $body): Message
    {
        [$message] = app(MessageService::class)->send($sender, $conversation, [
            'client_message_id' => (string) Str::uuid(),
            'body' => $body,
        ]);

        return $message;
    }
}
