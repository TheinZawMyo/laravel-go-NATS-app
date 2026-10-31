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

class ReadReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_advancing_the_cursor_queues_a_read_receipt(): void
    {
        [$actor, $reader, $conversation] = $this->directConversation();

        $message = $this->sendMessage($actor, $conversation, 'Read me');

        $this
            ->actingAs($reader)
            ->postJson("/api/v1/conversations/{$conversation->id}/read", ['seq' => $message->seq])
            ->assertOk()
            ->assertJsonPath('data.last_read_seq', $message->seq);

        $event = OutboxEvent::query()->where('event_type', 'receipt.updated')->sole();

        $this->assertSame('conversation', $event->aggregate_type);
        $this->assertSame($conversation->id, $event->aggregate_id);

        $payload = $event->payload;

        $this->assertSame('receipt.updated', $payload['type']);
        $this->assertSame($conversation->id, $payload['conversation_id']);
        $this->assertSame($message->seq, $payload['seq']);
        $this->assertSame($conversation->id, $payload['data']['conversation_id']);
        $this->assertSame($reader->id, $payload['data']['user_id']);
        $this->assertSame($message->id, $payload['data']['message_id']);
        $this->assertSame($message->seq, $payload['data']['seq']);
        $this->assertTrue($payload['data']['read']);
    }

    public function test_a_stale_cursor_does_not_queue_another_receipt(): void
    {
        [$actor, $reader, $conversation] = $this->directConversation();

        $this->sendMessage($actor, $conversation, 'One');
        $second = $this->sendMessage($actor, $conversation, 'Two');

        $this->actingAs($reader)
            ->postJson("/api/v1/conversations/{$conversation->id}/read", ['seq' => $second->seq])
            ->assertOk();

        // The cursor cannot move backwards, so no second receipt may be queued.
        $this->actingAs($reader)
            ->postJson("/api/v1/conversations/{$conversation->id}/read", ['seq' => 1])
            ->assertOk()
            ->assertJsonPath('data.last_read_seq', $second->seq);

        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'receipt.updated')->count());
    }

    public function test_a_cursor_past_the_end_is_clamped(): void
    {
        [$actor, $reader, $conversation] = $this->directConversation();

        $this->sendMessage($actor, $conversation, 'Only');

        $this
            ->actingAs($reader)
            ->postJson("/api/v1/conversations/{$conversation->id}/read", ['seq' => 99])
            ->assertOk()
            ->assertJsonPath('data.last_read_seq', 1);
    }

    public function test_the_conversation_resource_exposes_every_members_cursor(): void
    {
        [$actor, $reader, $conversation] = $this->directConversation();

        $first = $this->sendMessage($actor, $conversation, 'Read me');
        $second = $this->sendMessage($actor, $conversation, 'Not yet');

        $this->actingAs($reader)
            ->postJson("/api/v1/conversations/{$conversation->id}/read", ['seq' => $first->seq])
            ->assertOk();

        $members = collect(
            $this->actingAs($actor)
                ->getJson("/api/v1/conversations/{$conversation->id}")
                ->assertOk()
                ->json('data.members'),
        )->keyBy('user.id');

        $this->assertSame($first->seq, $members[$reader->id]['last_read_seq']);
        // Sending a message advances the sender's own cursor to that message.
        $this->assertSame($second->seq, $members[$actor->id]['last_read_seq']);
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
