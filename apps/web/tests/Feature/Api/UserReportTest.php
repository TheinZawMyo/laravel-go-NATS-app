<?php

namespace Tests\Feature\Api;

use App\Enums\UserReportStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserReport;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_report_another_user(): void
    {
        $reporter = User::factory()->create();
        $target = User::factory()->create(['username' => 'reported']);

        $this
            ->actingAs($reporter)
            ->postJson('/api/v1/reports', [
                'username' => 'reported',
                'reason' => 'spam',
                'details' => 'They keep sending junk.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.reported_user.username', 'reported')
            ->assertJsonPath('data.status', 'open');

        $this->assertDatabaseHas('user_reports', [
            'reporter_id' => $reporter->id,
            'reported_user_id' => $target->id,
            'reason' => 'spam',
        ]);

        $this
            ->actingAs($reporter)
            ->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_users_can_report_a_message_they_can_see(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $conversation = app(ConversationService::class)->openDirect($alice, $bob);
        $message = $this->say($alice, $conversation, 'Bad message');

        $this
            ->actingAs($bob)
            ->postJson('/api/v1/reports', [
                'message_id' => $message->id,
                'reason' => 'harassment',
            ])
            ->assertCreated()
            ->assertJsonPath('data.reported_user.id', $alice->id)
            ->assertJsonPath('data.reported_message.id', $message->id);
    }

    public function test_users_cannot_report_a_message_from_an_unauthorized_conversation(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $mallory = User::factory()->create();

        $conversation = app(ConversationService::class)->openDirect($alice, $bob);
        $message = $this->say($alice, $conversation, 'Private message');

        $this
            ->actingAs($mallory)
            ->postJson('/api/v1/reports', [
                'message_id' => $message->id,
                'reason' => 'spam',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('user_reports', 0);
    }

    public function test_users_cannot_report_themselves(): void
    {
        $user = User::factory()->create(['username' => 'selfie']);

        $this
            ->actingAs($user)
            ->postJson('/api/v1/reports', [
                'username' => 'selfie',
                'reason' => 'other',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_duplicate_open_reports_are_rejected(): void
    {
        $reporter = User::factory()->create();
        User::factory()->create(['username' => 'target']);

        $payload = ['username' => 'target', 'reason' => 'spam'];

        $this->actingAs($reporter)->postJson('/api/v1/reports', $payload)->assertCreated();

        $this
            ->actingAs($reporter)
            ->postJson('/api/v1/reports', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_moderators_can_list_and_resolve_reports(): void
    {
        $reporter = User::factory()->create();
        $target = User::factory()->create(['username' => 'target']);
        $admin = User::factory()->admin()->create();

        $report = UserReport::query()->create([
            'reporter_id' => $reporter->id,
            'reported_user_id' => $target->id,
            'reason' => 'spam',
            'status' => UserReportStatus::Open,
        ]);

        $this
            ->actingAs($admin)
            ->getJson('/api/v1/admin/reports?status=open')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $report->id);

        $this
            ->actingAs($admin)
            ->postJson("/api/v1/admin/reports/{$report->id}/resolve", [
                'status' => 'resolved',
                'action_taken' => 'warning',
                'note' => 'Sent a warning.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.action_taken', 'warning');

        $report->refresh();

        $this->assertSame($admin->id, $report->reviewed_by);
        $this->assertNotNull($report->reviewed_at);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'report.resolved',
            'subject_id' => $report->id,
        ]);
    }

    public function test_non_moderators_cannot_use_moderation_endpoints(): void
    {
        $user = User::factory()->create();
        User::factory()->create(['username' => 'target']);
        $report = UserReport::query()->create([
            'reporter_id' => $user->id,
            'reported_user_id' => User::query()->where('username', 'target')->sole()->id,
            'reason' => 'spam',
            'status' => UserReportStatus::Open,
        ]);

        $this->actingAs($user)->getJson('/api/v1/admin/reports')->assertForbidden();
        $this->actingAs($user)
            ->postJson("/api/v1/admin/reports/{$report->id}/resolve", ['status' => 'dismissed'])
            ->assertForbidden();
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
