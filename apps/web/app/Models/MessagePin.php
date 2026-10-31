<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Message pin inside a conversation.
 *
 * The table has a composite primary key, so this model is only used for typed
 * queries and never saved directly: MessageService writes it with the query
 * builder to keep the pin/unpin path idempotent.
 *
 * @property string $conversation_id
 * @property string $message_id
 * @property string $pinned_by
 * @property Carbon $pinned_at
 * @property-read Message $message
 * @property-read User $pinnedBy
 */
#[Fillable(['conversation_id', 'message_id', 'pinned_by', 'pinned_at'])]
class MessagePin extends Model
{
    /**
     * @var string
     */
    protected $table = 'message_pins';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by');
    }
}
