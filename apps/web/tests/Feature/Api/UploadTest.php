<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Services\UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config(['chat.media.disk' => 'media']);
    }

    public function test_presign_requires_authentication(): void
    {
        $this
            ->postJson('/api/v1/uploads/presign', [
                'original_name' => 'photo.png',
                'mime_type' => 'image/png',
                'size_bytes' => 10,
            ])
            ->assertUnauthorized();
    }

    public function test_presign_rejects_disallowed_and_oversized_files(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->postJson('/api/v1/uploads/presign', [
                'original_name' => 'evil.html',
                'mime_type' => 'text/html',
                'size_bytes' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mime_type');

        $this
            ->actingAs($user)
            ->postJson('/api/v1/uploads/presign', [
                'original_name' => 'huge.png',
                'mime_type' => 'image/png',
                'size_bytes' => (int) config('chat.media.max_bytes') + 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('size_bytes');
    }

    public function test_presign_creates_a_pending_row_with_a_signed_upload_url(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->postJson('/api/v1/uploads/presign', [
                'original_name' => 'photo.png',
                'mime_type' => 'image/png',
                'size_bytes' => 1024,
            ])
            ->assertCreated();

        $attachmentId = $response->json('data.id');
        $uploadUrl = $response->json('upload.url');

        $this->assertIsString($attachmentId);
        $this->assertStringContainsString('signature=', (string) $uploadUrl);
        $this->assertSame('PUT', $response->json('upload.method'));

        $attachment = MessageAttachment::query()->findOrFail($attachmentId);

        $this->assertSame($user->id, $attachment->uploader_id);
        $this->assertNull($attachment->message_id);
        $this->assertNull($attachment->uploaded_at);
        $this->assertNotNull($attachment->expires_at);
        $this->assertSame('photo.png', $attachment->original_name);
        // Storage keys are server-generated: random material under a random
        // directory, never a client-provided path.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/[a-z0-9]+\.png$/',
            $attachment->storage_key,
        );
    }

    public function test_signed_upload_stores_valid_image_bytes(): void
    {
        $user = User::factory()->create();
        [$attachmentId, $png] = $this->presign($user, 'photo.png', 'image/png');

        $this
            ->call(
                'PUT',
                $this->uploadUrl($attachmentId),
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'image/png', 'CONTENT_LENGTH' => (string) strlen($png)],
                $png,
            )
            ->assertOk();

        $attachment = MessageAttachment::query()->findOrFail($attachmentId);

        $this->assertNotNull($attachment->uploaded_at);
        $this->assertSame(1, $attachment->width);
        $this->assertSame(1, $attachment->height);
        Storage::disk('media')->assertExists($attachment->storage_key);
    }

    public function test_upload_rejects_bytes_that_do_not_match_the_declaration(): void
    {
        $user = User::factory()->create();

        // Declared as an image, but the bytes are plain text of the same
        // length, so only the content check can reject it.
        [$textId, $png] = $this->presign($user, 'fake.png', 'image/png');
        $decoy = str_repeat('x', strlen($png));

        $this
            ->call(
                'PUT',
                $this->uploadUrl($textId),
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'image/png', 'CONTENT_LENGTH' => (string) strlen($decoy)],
                $decoy,
            )
            ->assertUnprocessable();

        $this->assertNull(MessageAttachment::query()->findOrFail($textId)->uploaded_at);

        // Declared size does not match the actual payload.
        [$sizeId] = $this->presign($user, 'photo.png', 'image/png', strlen($png) + 5);

        $this
            ->call(
                'PUT',
                $this->uploadUrl($sizeId),
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'image/png', 'CONTENT_LENGTH' => (string) strlen($png)],
                $png,
            )
            ->assertUnprocessable();

        $this->assertNull(MessageAttachment::query()->findOrFail($sizeId)->uploaded_at);
    }

    public function test_expired_upload_urls_are_rejected(): void
    {
        $user = User::factory()->create();
        [$attachmentId, $png] = $this->presign($user, 'photo.png', 'image/png');

        $this->travel(
            ((int) config('chat.media.upload_ttl_minutes')) + 1
        )->minutes();

        $this
            ->call(
                'PUT',
                $this->uploadUrl($attachmentId),
                [],
                [],
                [],
                ['CONTENT_LENGTH' => (string) strlen($png)],
                $png,
            )
            ->assertForbidden();

        $this->assertNull(MessageAttachment::query()->findOrFail($attachmentId)->uploaded_at);
    }

    public function test_messages_can_carry_uploaded_attachments(): void
    {
        [$actor, $other, $conversation] = $this->directConversation();
        $attachmentId = $this->upload($actor, 'photo.png', 'image/png');

        $response = $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => '',
                'attachment_ids' => [$attachmentId],
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.attachments.0.id', $attachmentId)
            ->assertJsonPath('data.attachments.0.kind', 'image')
            ->assertJsonPath('data.attachments.0.name', 'photo.png');

        $attachment = MessageAttachment::query()->findOrFail($attachmentId);

        $this->assertSame($response->json('data.id'), $attachment->message_id);

        $messageId = $response->json('data.id');

        $this
            ->actingAs($other)
            ->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.id', $messageId)
            ->assertJsonPath('data.0.attachments.0.id', $attachmentId);

        $this
            ->actingAs($other)
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.last_message.attachments.0.id', $attachmentId);

        $event = OutboxEvent::query()->sole();

        $this->assertSame($attachmentId, $event->payload['data']['attachments'][0]['id']);
    }

    public function test_a_message_needs_a_body_or_at_least_one_attachment(): void
    {
        [$actor, , $conversation] = $this->directConversation();

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_only_ready_attachments_owned_by_the_sender_can_be_sent(): void
    {
        [$actor, $other, $conversation] = $this->directConversation();

        $foreign = $this->upload($other, 'theirs.png', 'image/png');

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => '',
                'attachment_ids' => [$foreign],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment_ids');

        // Presigned but never uploaded: no bytes, so it cannot be sent.
        [$pendingId] = $this->presign($actor, 'pending.png', 'image/png', 10);

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => '',
                'attachment_ids' => [$pendingId],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment_ids');

        $this->assertSame(0, Message::query()->count());
    }

    public function test_downloads_require_conversation_membership(): void
    {
        [$actor, $other, $conversation] = $this->directConversation();
        $outsider = User::factory()->create();
        $attachmentId = $this->upload($actor, 'photo.png', 'image/png');

        $response = $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => '',
                'attachment_ids' => [$attachmentId],
            ])
            ->assertCreated();

        $messageId = (string) $response->json('data.id');
        $attachment = MessageAttachment::query()->findOrFail($attachmentId);

        $allowed = $this
            ->actingAs($other)
            ->get("/api/v1/messages/{$messageId}/attachments/{$attachmentId}");

        $allowed
            ->assertOk()
            ->assertHeader('content-type', 'image/png')
            ->assertHeader('content-disposition', 'inline; filename=photo.png');
        $this->assertSame($this->pngBytes(), $allowed->streamedContent());

        $this
            ->actingAs($outsider)
            ->getJson("/api/v1/messages/{$messageId}/attachments/{$attachmentId}")
            ->assertForbidden();

        $wrongMessage = $this->sendMessage($actor, $conversation, 'Other message');

        $this
            ->actingAs($actor)
            ->getJson("/api/v1/messages/{$wrongMessage->id}/attachments/{$attachmentId}")
            ->assertNotFound();

        $this->assertStringNotContainsString(
            'public',
            (string) config('filesystems.disks.media.url'),
        );
        $this->assertFileDoesNotExist(
            public_path('storage/'.$attachment->storage_key),
        );
    }

    public function test_media_gallery_is_scoped_to_the_conversation(): void
    {
        [$actor, $other, $conversation] = $this->directConversation();
        $outsider = User::factory()->create();

        $first = $this->upload($actor, 'one.png', 'image/png');
        $second = $this->upload($other, 'two.png', 'image/png');

        $this->actingAs($actor)->postJson(
            "/api/v1/conversations/{$conversation->id}/messages",
            ['client_message_id' => (string) Str::uuid(), 'body' => 'first', 'attachment_ids' => [$first]],
        )->assertCreated();

        $this->actingAs($other)->postJson(
            "/api/v1/conversations/{$conversation->id}/messages",
            ['client_message_id' => (string) Str::uuid(), 'body' => 'second', 'attachment_ids' => [$second]],
        )->assertCreated();

        $this
            ->actingAs($actor)
            ->getJson("/api/v1/conversations/{$conversation->id}/media")
            ->assertOk()
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.1.id', $first)
            ->assertJsonPath('meta.has_more', false);

        $this
            ->actingAs($outsider)
            ->getJson("/api/v1/conversations/{$conversation->id}/media")
            ->assertForbidden();

        // A second conversation's media never leaks into this gallery.
        $third = User::factory()->create();
        $elsewhere = app(ConversationService::class)->openDirect($actor, $third);
        $foreign = $this->upload($actor, 'foreign.png', 'image/png');

        $this->actingAs($actor)->postJson(
            "/api/v1/conversations/{$elsewhere->id}/messages",
            ['client_message_id' => (string) Str::uuid(), 'body' => '', 'attachment_ids' => [$foreign]],
        )->assertCreated();

        $this
            ->actingAs($actor)
            ->getJson("/api/v1/conversations/{$conversation->id}/media")
            ->assertOk()
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonMissing(['id' => $foreign]);
    }

    public function test_prune_command_removes_abandoned_uploads_and_their_bytes(): void
    {
        $user = User::factory()->create();
        [$pendingId] = $this->presign($user, 'abandoned.png', 'image/png', 10);
        $attachedId = $this->upload($user, 'kept.png', 'image/png');

        [$message] = app(MessageService::class)->send($user, $this->conversationFor($user), [
            'client_message_id' => (string) Str::uuid(),
            'body' => '',
            'attachment_ids' => [$attachedId],
        ]);

        $pending = MessageAttachment::query()->findOrFail($pendingId);
        Storage::disk('media')->put($pending->storage_key, 'bytes');

        $this->travel(
            ((int) config('chat.media.pending_retention_hours')) + 1
        )->hours();

        $this->artisan('media:prune-uploads')->assertSuccessful();

        $this->assertNull(MessageAttachment::query()->find($pendingId));
        Storage::disk('media')->assertMissing($pending->storage_key);
        $this->assertNotNull(MessageAttachment::query()->find($attachedId));
        $this->assertNotNull($message->fresh());
    }

    public function test_a_client_message_id_replays_without_reattaching(): void
    {
        [$actor, , $conversation] = $this->directConversation();
        $attachmentId = $this->upload($actor, 'photo.png', 'image/png');
        $clientMessageId = (string) Str::uuid();

        $payload = [
            'client_message_id' => $clientMessageId,
            'body' => '',
            'attachment_ids' => [$attachmentId],
        ];

        $first = $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", $payload)
            ->assertCreated();

        $second = $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", $payload)
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Message::query()->count());
        $this->assertSame(
            $first->json('data.attachments.0.id'),
            $second->json('data.attachments.0.id'),
        );
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

    private function conversationFor(User $user): Conversation
    {
        $other = User::factory()->create();

        return app(ConversationService::class)->openDirect($user, $other);
    }

    private function sendMessage(User $sender, Conversation $conversation, string $body): Message
    {
        [$message] = app(MessageService::class)->send($sender, $conversation, [
            'client_message_id' => (string) Str::uuid(),
            'body' => $body,
        ]);

        return $message;
    }

    /**
     * @return array{0: string, 1: string} The attachment id and the raw bytes.
     */
    private function presign(
        User $uploader,
        string $name,
        string $mime,
        ?int $sizeBytes = null,
    ): array {
        $png = $this->pngBytes();

        $response = $this
            ->actingAs($uploader)
            ->postJson('/api/v1/uploads/presign', [
                'original_name' => $name,
                'mime_type' => $mime,
                'size_bytes' => $sizeBytes ?? strlen($png),
            ])
            ->assertCreated();

        return [(string) $response->json('data.id'), $png];
    }

    /**
     * Presign, PUT the bytes, and return the attachment id.
     */
    private function upload(User $uploader, string $name, string $mime): string
    {
        [$attachmentId, $png] = $this->presign($uploader, $name, $mime);

        $this
            ->call(
                'PUT',
                $this->uploadUrl($attachmentId),
                [],
                [],
                [],
                ['CONTENT_TYPE' => $mime, 'CONTENT_LENGTH' => (string) strlen($png)],
                $png,
            )
            ->assertOk();

        return $attachmentId;
    }

    private function uploadUrl(string $attachmentId): string
    {
        $attachment = MessageAttachment::query()->findOrFail($attachmentId);

        return app(UploadService::class)->uploadUrl($attachment);
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(1, 1);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
