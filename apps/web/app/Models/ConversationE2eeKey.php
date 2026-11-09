<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A version of a conversation's symmetric content key.
 *
 * The key itself is never stored. Every member receives the key wrapped with
 * their public identity key in `conversation_key_wraps`.
 */
#[Fillable(['conversation_id', 'key_version', 'created_by'])]
class ConversationE2eeKey extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key_version' => 'integer',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ConversationKeyWrap, $this>
     */
    public function wraps(): HasMany
    {
        return $this->hasMany(ConversationKeyWrap::class, 'conversation_key_id');
    }
}
