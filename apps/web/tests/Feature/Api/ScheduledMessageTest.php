<?php

namespace Tests\Feature\Api;

use App\Models\ScheduledMessage;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScheduledMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_can_schedule_a_message(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09T12:00:00Z'));
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = app(ConversationService::class)->openDirect($sender, $recipient);
        $sendAt = Carbon::now()->addHour();

        $response = $this
            ->actingAs($sender)
            ->postJson("/api/v1/conversations/{$conversation->id}/scheduled-messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => 'Send this later',
                'send_at' => $sendAt->toIso8601String(),
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.body', 'Send this later')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('scheduled_messages', [
            'id' => $response->json('data.id'),
            'sender_id' => $sender->id,
            'conversation_id' => $conversation->id,
            'status' => 'pending',
        ]);

        Carbon::setTestNow();
    }
}
