<?php

namespace App\Models;

use App\Enums\ContactRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pending or answered "add me to your contacts" request.
 *
 * @property string $id
 * @property string $sender_id
 * @property string $receiver_id
 * @property ContactRequestStatus $status
 * @property string|null $message
 * @property Carbon|null $responded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $sender
 * @property-read User $receiver
 */
#[Fillable(['sender_id', 'receiver_id', 'status', 'message', 'responded_at'])]
class ContactRequest extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContactRequestStatus::class,
            'responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    /**
     * The user on the other side of the request for the given viewer.
     */
    public function counterpartFor(User $viewer): ?User
    {
        if ($this->sender_id === $viewer->id) {
            return $this->receiver;
        }

        if ($this->receiver_id === $viewer->id) {
            return $this->sender;
        }

        return null;
    }
}
