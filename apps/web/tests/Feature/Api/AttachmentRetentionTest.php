<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Services\UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttachmentRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config(['chat.media.disk' => 'media']);

        // Keep scanning out of the way: this suite is about retention.
        config(['chat.media.scan.enabled' => false]);
    }

    public function test_downloads_stop_working_as_soon_as_the_message_is_deleted(): void
    {
        [$actor, $other, $conversation] = $this->directConversation();
        $attachmentId = $this->uploadAndSend($actor, $conversation);
        $message = Message::query()->findOrFail(
            MessageAttachment::query()->findOrFail($attachmentId)->message_id,
        );

        app(MessageService::class)->delete($message);

        $this
            ->actingAs($other)
            ->getJson("/api/v1/messages/{$message->id}/attachments/{$attachmentId}")
            ->assertNotFound();

        // The row survives for the grace period so the deletion stays
        // auditable; only the bytes become unreachable.
        $this->assertNotNull(MessageAttachment::query()->find($attachmentId));
    }

    public function test_the_purge_command_reclaims_bytes_of_deleted_messages_after_the_grace_period(): void
    {
        [$actor, , $conversation] = $this->directConversation();
        $attachmentId = $this->uploadAndSend($actor, $conversation);
        $attachment = MessageAttachment::query()->findOrFail($attachmentId);
        $message = Message::query()->findOrFail($attachment->message_id);

        app(MessageService::class)->delete($message);

        $this->travel(
            ((int) config('chat.media.deleted_message_grace_days')) + 1
        )->days();

        $this->artisan('media:purge-attachments')->assertSuccessful();

        $this->assertNull(MessageAttachment::query()->find($attachmentId));
        Storage::disk('media')->assertMissing($attachment->storage_key);
    }

    public function test_the_purge_command_keeps_recent_deletions_and_live_messages(): void
    {
        [$actor, , $conversation] = $this->directConversation();

        $recentlyDeletedId = $this->uploadAndSend($actor, $conversation);
        $liveId = $this->uploadAndSend($actor, $conversation);

        $recentlyDeleted = MessageAttachment::query()->findOrFail($recentlyDeletedId);
        $live = MessageAttachment::query()->findOrFail($liveId);

        app(MessageService::class)->delete(
            Message::query()->findOrFail($recentlyDeleted->message_id),
        );

        $this->artisan('media:purge-attachments')->assertSuccessful();

        $this->assertNotNull(MessageAttachment::query()->find($recentlyDeletedId));
        $this->assertNotNull(MessageAttachment::query()->find($liveId));
        Storage::disk('media')->assertExists($live->storage_key);
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

    private function uploadAndSend(User $sender, Conversation $conversation): string
    {
        $png = $this->pngBytes();

        $attachmentId = (string) $this
            ->actingAs($sender)
            ->postJson('/api/v1/uploads/presign', [
                'original_name' => 'photo.png',
                'mime_type' => 'image/png',
                'size_bytes' => strlen($png),
            ])
            ->assertCreated()
            ->json('data.id');

        $attachment = MessageAttachment::query()->findOrFail($attachmentId);

        $this
            ->call(
                'PUT',
                app(UploadService::class)->uploadUrl($attachment),
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'image/png', 'CONTENT_LENGTH' => (string) strlen($png)],
                $png,
            )
            ->assertOk();

        $this
            ->actingAs($sender)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => '',
                'attachment_ids' => [$attachmentId],
            ])
            ->assertCreated();

        return $attachmentId;
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(1, 1);
        $this->assertNotFalse($image);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        $this->assertIsString($bytes);

        return $bytes;
    }
}
