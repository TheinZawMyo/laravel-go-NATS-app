<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_export_their_account_data(): void
    {
        $user = User::factory()->create(['username' => 'exporter']);
        $other = User::factory()->create(['username' => 'other']);

        $conversation = app(ConversationService::class)->openDirect($user, $other);

        app(MessageService::class)->send($user, $conversation, [
            'client_message_id' => (string) Str::uuid(),
            'body' => 'My own message',
        ]);

        app(MessageService::class)->send($other, $conversation, [
            'client_message_id' => (string) Str::uuid(),
            'body' => 'Someone else message',
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/v1/me/export')
            ->assertOk()
            ->assertHeader('Content-Disposition')
            ->assertJsonPath('account.username', 'exporter')
            ->assertJsonPath('privacy.profile_visibility', 'everyone')
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'My own message')
            ->assertJsonCount(1, 'conversations');

        $payload = $response->json();

        $this->assertArrayNotHasKey('password', $payload['account']);
        $this->assertArrayNotHasKey('two_factor_secret', $payload['account']);
        $this->assertArrayNotHasKey('remember_token', $payload['account']);
    }

    public function test_guests_cannot_export_account_data(): void
    {
        $this->getJson('/api/v1/me/export')->assertUnauthorized();
    }

    public function test_the_export_does_not_leak_other_users_messages(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $conversation = app(ConversationService::class)->openDirect($other, $user);

        app(MessageService::class)->send($other, $conversation, [
            'client_message_id' => (string) Str::uuid(),
            'body' => 'Keep this private',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/me/export')->assertOk();

        $this->assertStringNotContainsString(
            'Keep this private',
            $response->getContent(),
        );
    }
}
