<?php

namespace App\Models;

use App\Enums\MessageType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $conversation_id
 * @property int $seq
 * @property string $sender_id
 * @property string $client_message_id
 * @property MessageType $type
 * @property string|null $body
 * @property string|null $reply_to_message_id
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property-read User $sender
 * @property-read Message|null $replyTo
 * @property-read Conversation $conversation
 */
#[Fillable([
    'conversation_id',
    'seq',
    'sender_id',
    'client_message_id',
    'type',
    'body',
    'reply_to_message_id',
    'forwarded_from_message_id',
])]
class Message extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'type' => MessageType::class,
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id')->withTrashed();
    }

    /**
     * @return HasMany<MessageEdit, $this>
     */
    public function edits(): HasMany
    {
        return $this->hasMany(MessageEdit::class);
    }

    /**
     * @return HasMany<MessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }
}
