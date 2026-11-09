<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The public half of a user's end-to-end encryption identity key.
 *
 * Private keys never leave the client. A user has one identity key that can be
 * imported on additional devices through the recovery-key flow, which keeps
 * conversation wraps addressed to the user rather than to one browser.
 */
#[Fillable(['user_id', 'public_key', 'algorithm', 'fingerprint'])]
class E2eeIdentityKey extends Model
{
    use HasUuids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
