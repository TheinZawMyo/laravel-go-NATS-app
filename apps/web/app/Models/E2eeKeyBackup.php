<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An encrypted copy of the private identity key.
 *
 * The server stores only ciphertext derived from a user passphrase; it has no
 * way to decrypt the backup or the identity key.
 */
#[Fillable(['user_id', 'ciphertext', 'salt', 'iv', 'kdf', 'iterations', 'fingerprint'])]
class E2eeKeyBackup extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'iterations' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
