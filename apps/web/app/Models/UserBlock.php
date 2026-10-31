<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-way block. Either direction of a block pair hides the users from each
 * other and prevents direct messages between them.
 *
 * @property string $id
 * @property string $blocker_id
 * @property string $blocked_id
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property-read User $blocker
 * @property-read User $blocked
 */
#[Fillable(['blocker_id', 'blocked_id', 'reason'])]
class UserBlock extends Model
{
    use HasUuids;

    /**
     * The table only stores a creation timestamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }
}
