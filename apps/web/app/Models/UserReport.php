<?php

namespace App\Models;

use App\Enums\ReportReason;
use App\Enums\UserReportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $reporter_id
 * @property string|null $reported_user_id
 * @property string|null $reported_message_id
 * @property ReportReason $reason
 * @property string|null $details
 * @property UserReportStatus $status
 * @property string|null $action_taken
 * @property string|null $resolution_note
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $reporter
 * @property-read User|null $reportedUser
 * @property-read Message|null $reportedMessage
 * @property-read User|null $reviewer
 */
#[Fillable([
    'reporter_id',
    'reported_user_id',
    'reported_message_id',
    'reason',
    'details',
    'status',
    'action_taken',
    'resolution_note',
    'reviewed_by',
    'reviewed_at',
])]
class UserReport extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'status' => UserReportStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reportedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function reportedMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reported_message_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
