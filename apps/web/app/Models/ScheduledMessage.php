<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $conversation_id
 * @property string $sender_id
 * @property string $client_message_id
 * @property string $body
 * @property Carbon $send_at
 * @property string $status
 * @property string|null $message_id
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 */
#[Fillable(['conversation_id', 'sender_id', 'client_message_id', 'body', 'send_at', 'status', 'message_id', 'last_error', 'sent_at'])]
class ScheduledMessage extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
