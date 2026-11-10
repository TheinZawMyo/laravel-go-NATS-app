<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Services\Translation\TranslationException;
use App\Services\Translation\TranslationResult;
use App\Services\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MessageTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_can_translate_a_message_and_the_result_is_cached(): void
    {
        $translator = new FakeTranslator;
        $this->app->instance(Translator::class, $translator);

        $sender = User::factory()->create();
        $viewer = User::factory()->create();
        $conversation = app(ConversationService::class)->openDirect($sender, $viewer);
        $message = $this->say($sender, $conversation, 'Hello');

        $this
            ->actingAs($viewer)
            ->postJson("/api/v1/messages/{$message->id}/translate", ['language' => 'my'])
            ->assertOk()
            ->assertJsonPath('data.message_id', $message->id)
            ->assertJsonPath('data.target_language', 'my')
            ->assertJsonPath('data.source_language', 'en')
            ->assertJsonPath('data.translated_body', 'Hello [my]')
            ->assertJsonPath('data.provider', 'fake');

        // Another member requesting the same pair reads the cached row.
        $this
            ->actingAs($sender)
            ->postJson("/api/v1/messages/{$message->id}/translate", ['language' => 'my'])
            ->assertOk()
            ->assertJsonPath('data.translated_body', 'Hello [my]');

        $this->assertSame(1, $translator->calls);
        $this->assertDatabaseCount('message_translations', 1);
    }

    public function test_editing_a_message_invalidates_its_cached_translation(): void
    {
        $translator = new FakeTranslator;
        $this->app->instance(Translator::class, $translator);

        $sender = User::factory()->create();
        $viewer = User::factory()->create();
        $conversation = app(ConversationService::class)->openDirect($sender, $viewer);
        $message = $this->say($sender, $conversation, 'Hello');

        $this
            ->actingAs($viewer)
            ->postJson("/api/v1/messages/{$message->id}/translate", ['language' => 'my'])
            ->assertOk();

        $edited = app(MessageService::class)->edit($sender, $message->fresh(), 'Hello again');

        $this
            ->actingAs($viewer)
            ->postJson("/api/v1/messages/{$message->id}/translate", ['language' => 'my'])
            ->assertOk()
            ->assertJsonPath('data.translated_body', 'Hello again [my]');

        $this->assertSame(2, $translator->calls);
        $this->assertSame($edited->id, $message->id);
        $this->assertDatabaseCount('message_translations', 1);
    }

    public function test_outsiders_cannot_translate_a_message(): void
    {
        $this->app->instance(Translator::class, new FakeTranslator);

        $sender = User::factory()->create();
        $viewer = User::factory()->create();
        $conversation = app(ConversationService::class)->openDirect($sender, $viewer);
        $message = $this->say($sender, $conversation, 'Hello');
        $outsider = User::factory()->create();

        $this
            ->actingAs($outsider)
            ->postJson("/api/v1/messages/{$message->id}/translate", ['language' => 'my'])
            ->assertForbidden();
    }

    public function test_encrypted_messages_cannot_be_translated_and_deleted_messages_are_gone(): void
    {
        $this->app->instance(Translator::class, new FakeTranslator);

        $sender = User::factory()->create();
        $conversation = app(ConversationService::class)->openDirect($sender, User::factory()->create());
        $message = $this->say($sender, $conversation, 'Hello');

        $message->forceFill(['is_encrypted' => true, 'body' => null])->save();

        $this
            ->actingAs($sender)
            ->postJson("/api/v1/messages/{$message->id}/translate", ['language' => 'my'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('language');

        $message->forceFill(['is_encrypted' => false, 'body' => 'Hello'])->save();
        app(MessageService::class)->delete($message->fresh());

        $this
            ->actingAs($sender)
            ->postJson("/api/v1/messages/{$message->id}/translate", ['language' => 'my'])
            ->assertNotFound();
    }

    public function test_unsupported_languages_are_rejected(): void
    {
        $this->app->instance(Translator::class, new FakeTranslator);

        $sender = User::factory()->create();
        $conversation = app(ConversationService::class)->openDirect($sender, User::factory()->create());
        $message = $this->say($sender, $conversation, 'Hello');

        $this
            ->actingAs($sender)
            ->postJson("/api/v1/messages/{$message->id}/translate", ['language' => 'xx'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('language');
    }

    public function test_a_provider_outage_returns_a_retryable_error(): void
    {
        $this->app->instance(Translator::class, new FailingTranslator);

        $sender = User::factory()->create();
        $conversation = app(ConversationService::class)->openDirect($sender, User::factory()->create());
        $message = $this->say($sender, $conversation, 'Hello');

        $this
            ->actingAs($sender)
            ->postJson("/api/v1/messages/{$message->id}/translate", ['language' => 'my'])
            ->assertStatus(503);

        $this->assertDatabaseCount('message_translations', 0);
    }

    public function test_a_user_can_save_a_default_translation_language(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'translation_language' => 'ja',
            ])
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('ja', $user->fresh()->translation_language);

        $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'translation_language' => 'xx',
            ])
            ->assertSessionHasErrors('translation_language');
    }

    private function say(User $sender, Conversation $conversation, string $body): Message
    {
        [$message] = app(MessageService::class)->send($sender, $conversation, [
            'client_message_id' => (string) Str::uuid(),
            'body' => $body,
        ]);

        return $message;
    }
}

class FakeTranslator implements Translator
{
    public int $calls = 0;

    public function provider(): string
    {
        return 'fake';
    }

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
    ): TranslationResult {
        $this->calls++;

        return new TranslationResult(
            text: $text.' ['.$targetLanguage.']',
            targetLanguage: $targetLanguage,
            sourceLanguage: $sourceLanguage ?? 'en',
        );
    }
}

class FailingTranslator implements Translator
{
    public function provider(): string
    {
        return 'fake';
    }

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
    ): TranslationResult {
        throw new TranslationException('provider down');
    }
}
