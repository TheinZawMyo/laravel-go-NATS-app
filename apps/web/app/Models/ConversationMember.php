<?php

namespace App\Models;

use App\Enums\ConversationRole;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Membership row linking a user to a conversation.
 *
 * @property string $conversation_id
 * @property string $user_id
 * @property ConversationRole $role
 * @property Carbon $joined_at
 * @property Carbon|null $left_at
 * @property int $last_read_seq
 * @property Carbon|null $muted_until
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ConversationMember extends Pivot
{
    protected $table = 'conversation_members';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ConversationRole::class,
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'last_read_seq' => 'integer',
            'muted_until' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
