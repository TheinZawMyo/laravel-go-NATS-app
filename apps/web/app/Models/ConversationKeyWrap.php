<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recipient's wrapped copy of a conversation key.
 *
 * Wrapping uses an ephemeral ECDH key pair so any member can add a wrap for a
 * recipient without holding that recipient's private key.
 */
#[Fillable([
    'conversation_key_id',
    'recipient_user_id',
    'algorithm',
    'ephemeral_public_key',
    'iv',
    'ciphertext',
])]
class ConversationKeyWrap extends Model
{
    use HasUuids;

    /**
     * @return BelongsTo<ConversationE2eeKey, $this>
     */
    public function conversationKey(): BelongsTo
    {
        return $this->belongsTo(ConversationE2eeKey::class, 'conversation_key_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
