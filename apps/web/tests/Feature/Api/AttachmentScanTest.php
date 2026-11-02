<?php

namespace Tests\Feature\Api;

use App\Enums\AttachmentScanStatus;
use App\Models\Conversation;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MalwareScanner;
use App\Services\MalwareScanResult;
use App\Services\UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeMalwareScanner;
use Tests\TestCase;

class AttachmentScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config(['chat.media.disk' => 'media']);
    }

    public function test_an_infected_upload_is_rejected_before_it_reaches_the_disk(): void
    {
        $this->useScanner(MalwareScanResult::infected('Win.Test.EICAR_HDB-1'));

        $user = User::factory()->create();
        [$attachmentId, $png] = $this->presign($user);
        $attachment = MessageAttachment::query()->findOrFail($attachmentId);

        $this->putBytes($attachmentId, $png)->assertUnprocessable();

        $attachment->refresh();

        $this->assertNull($attachment->uploaded_at);
        $this->assertSame(AttachmentScanStatus::Infected, $attachment->scan_status);
        $this->assertNotNull($attachment->scanned_at);
        Storage::disk('media')->assertMissing($attachment->storage_key);
    }

    public function test_a_scanner_outage_rejects_uploads_when_the_deployment_fails_closed(): void
    {
        config(['chat.media.scan.fail_closed' => true]);
        $this->useScanner(MalwareScanResult::failed('Cannot reach the antivirus daemon.'));

        $user = User::factory()->create();
        [$attachmentId, $png] = $this->presign($user);
        $attachment = MessageAttachment::query()->findOrFail($attachmentId);

        $this->putBytes($attachmentId, $png)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('upload');

        $attachment->refresh();

        $this->assertSame(AttachmentScanStatus::Failed, $attachment->scan_status);
        $this->assertNull($attachment->uploaded_at);
        Storage::disk('media')->assertMissing($attachment->storage_key);
    }

    public function test_a_scanner_outage_accepts_the_upload_as_unverified_when_failing_open(): void
    {
        config(['chat.media.scan.fail_closed' => false]);
        $this->useScanner(MalwareScanResult::failed('Cannot reach the antivirus daemon.'));

        $user = User::factory()->create();
        $attachmentId = $this->upload($user);
        $attachment = MessageAttachment::query()->findOrFail($attachmentId);

        // "Skipped" is honest about the file not being verified, and unlike
        // "Failed" it does not block the send.
        $this->assertSame(AttachmentScanStatus::Skipped, $attachment->scan_status);
        $this->assertNotNull($attachment->uploaded_at);
        Storage::disk('media')->assertExists($attachment->storage_key);

        [, , $conversation] = $this->conversation($user);

        $this
            ->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => '',
                'attachment_ids' => [$attachmentId],
            ])
            ->assertCreated();
    }

    public function test_a_clean_upload_is_recorded_as_scanned(): void
    {
        $this->useScanner(MalwareScanResult::clean());

        $user = User::factory()->create();
        $attachmentId = $this->upload($user);
        $attachment = MessageAttachment::query()->findOrFail($attachmentId);

        $this->assertSame(AttachmentScanStatus::Clean, $attachment->scan_status);
        $this->assertTrue($attachment->isSendable());
    }

    public function test_attachments_that_failed_scanning_cannot_be_sent(): void
    {
        $this->useScanner(MalwareScanResult::clean());

        [$actor, , $conversation] = $this->conversation();
        $attachmentId = $this->upload($actor);

        // The bytes are on the disk but a later scan review marked them unsafe.
        MessageAttachment::query()
            ->whereKey($attachmentId)
            ->update(['scan_status' => AttachmentScanStatus::Infected->value]);

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => '',
                'attachment_ids' => [$attachmentId],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment_ids');
    }

    public function test_downloads_are_refused_for_attachments_that_are_not_sendable(): void
    {
        $this->useScanner(MalwareScanResult::clean());

        [$actor, $other, $conversation] = $this->conversation();
        $attachmentId = $this->upload($actor);

        $messageId = (string) $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => '',
                'attachment_ids' => [$attachmentId],
            ])
            ->assertCreated()
            ->json('data.id');

        MessageAttachment::query()
            ->whereKey($attachmentId)
            ->update(['scan_status' => AttachmentScanStatus::Failed->value]);

        $this
            ->actingAs($other)
            ->getJson("/api/v1/messages/{$messageId}/attachments/{$attachmentId}")
            ->assertNotFound();
    }

    public function test_an_unscanned_attachment_cannot_be_attached_to_a_message(): void
    {
        $this->useScanner(MalwareScanResult::clean());

        [$actor, , $conversation] = $this->conversation();
        $attachmentId = $this->upload($actor);

        // A row that never reached a verdict stays undeliverable until it does.
        MessageAttachment::query()->whereKey($attachmentId)->update([
            'scan_status' => AttachmentScanStatus::Pending->value,
            'scanned_at' => null,
        ]);

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => '',
                'attachment_ids' => [$attachmentId],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment_ids');
    }

    private function useScanner(MalwareScanResult $result): FakeMalwareScanner
    {
        $scanner = new FakeMalwareScanner($result);

        $this->app->instance(MalwareScanner::class, $scanner);

        return $scanner;
    }

    /**
     * @return array{0: User, 1: User, 2: Conversation}
     */
    private function conversation(?User $actor = null): array
    {
        $actor ??= User::factory()->create();
        $other = User::factory()->create();

        return [
            $actor,
            $other,
            app(ConversationService::class)->openDirect($actor, $other),
        ];
    }

    /**
     * @return array{0: string, 1: string} The attachment id and the raw bytes.
     */
    private function presign(User $uploader): array
    {
        $png = $this->pngBytes();

        $response = $this
            ->actingAs($uploader)
            ->postJson('/api/v1/uploads/presign', [
                'original_name' => 'photo.png',
                'mime_type' => 'image/png',
                'size_bytes' => strlen($png),
            ])
            ->assertCreated();

        return [(string) $response->json('data.id'), $png];
    }

    private function upload(User $uploader): string
    {
        [$attachmentId, $png] = $this->presign($uploader);

        $this->putBytes($attachmentId, $png)->assertOk();

        return $attachmentId;
    }

    private function putBytes(string $attachmentId, string $bytes): TestResponse
    {
        $attachment = MessageAttachment::query()->findOrFail($attachmentId);

        return $this->call(
            'PUT',
            app(UploadService::class)->uploadUrl($attachment),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'image/png',
                'CONTENT_LENGTH' => (string) strlen($bytes),
            ],
            $bytes,
        );
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
