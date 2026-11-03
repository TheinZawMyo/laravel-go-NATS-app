<?php

namespace App\Services;

use App\Enums\ReportReason;
use App\Enums\UserReportStatus;
use App\Models\Message;
use App\Models\User;
use App\Models\UserReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportService
{
    /**
     * A reporter cannot keep re-reporting the same target every second; the
     * window keeps the review queue meaningful without blocking a later,
     * separate incident.
     */
    public const DUPLICATE_WINDOW_HOURS = 24;

    public const DEFAULT_LIMIT = 50;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{username?: string|null, message_id?: string|null, reason: string, details?: string|null}  $data
     */
    public function report(User $reporter, array $data): UserReport
    {
        $message = null;
        $target = null;

        if (($data['message_id'] ?? null) !== null) {
            $message = Message::query()
                ->withTrashed()
                ->with('conversation')
                ->findOrFail((string) $data['message_id']);

            abort_unless(
                $message->conversation->hasActiveMember($reporter),
                403,
                __('You cannot report this message.'),
            );

            $target = $message->sender;
        } elseif (($data['username'] ?? null) !== null) {
            $target = User::query()
                ->where('username', Str::lower((string) $data['username']))
                ->firstOrFail();
        }

        if ($target === null) {
            throw ValidationException::withMessages([
                'username' => __('Choose a user or message to report.'),
            ]);
        }

        if ($target->is($reporter)) {
            throw ValidationException::withMessages([
                'username' => __('You cannot report yourself.'),
            ]);
        }

        $duplicate = UserReport::query()
            ->where('reporter_id', $reporter->id)
            ->where('reported_user_id', $target->id)
            ->when(
                $message !== null,
                fn (Builder $query): Builder => $query->where('reported_message_id', $message->id),
                fn (Builder $query): Builder => $query->whereNull('reported_message_id'),
            )
            ->where('status', UserReportStatus::Open)
            ->where('created_at', '>=', Carbon::now()->subHours(self::DUPLICATE_WINDOW_HOURS))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'reason' => __('You have already reported this. Our moderators are reviewing it.'),
            ]);
        }

        $report = UserReport::query()->create([
            'reporter_id' => $reporter->id,
            'reported_user_id' => $target->id,
            'reported_message_id' => $message?->id,
            'reason' => ReportReason::from($data['reason']),
            'details' => $data['details'] ?? null,
            'status' => UserReportStatus::Open,
        ]);

        return $report->load(['reporter', 'reportedUser', 'reportedMessage']);
    }

    /**
     * @return Collection<int, UserReport>
     */
    public function forReporter(User $reporter, int $limit = self::DEFAULT_LIMIT): Collection
    {
        return UserReport::query()
            ->with(['reportedUser', 'reportedMessage'])
            ->where('reporter_id', $reporter->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array{status?: string|null, reason?: string|null}  $filters
     * @return Collection<int, UserReport>
     */
    public function forModeration(array $filters = [], int $limit = self::DEFAULT_LIMIT): Collection
    {
        return UserReport::query()
            ->with(['reporter', 'reportedUser', 'reportedMessage.conversation', 'reviewer'])
            ->when(
                ($filters['status'] ?? null) !== null,
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->when(
                ($filters['reason'] ?? null) !== null,
                fn (Builder $query): Builder => $query->where('reason', $filters['reason']),
            )
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function resolve(
        User $moderator,
        UserReport $report,
        UserReportStatus $status,
        ?string $note = null,
        ?string $actionTaken = null,
    ): UserReport {
        if ($status === UserReportStatus::Open) {
            throw ValidationException::withMessages([
                'status' => __('Choose resolved or dismissed.'),
            ]);
        }

        DB::transaction(function () use ($moderator, $report, $status, $note, $actionTaken): void {
            $report->forceFill([
                'status' => $status,
                'resolution_note' => $note,
                'action_taken' => $actionTaken,
                'reviewed_by' => $moderator->id,
                'reviewed_at' => Carbon::now(),
            ])->save();

            $this->audit->record(
                'report.'.$status->value,
                $moderator,
                $report,
                [
                    'reported_user_id' => $report->reported_user_id,
                    'reported_message_id' => $report->reported_message_id,
                    'action_taken' => $actionTaken,
                ],
            );
        });

        return $report->refresh()->load([
            'reporter',
            'reportedUser',
            'reportedMessage',
            'reviewer',
        ]);
    }
}
