<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cached machine translation of one message into one target language.
 *
 * @property string $id
 * @property string $message_id
 * @property string $target_language
 * @property string|null $source_language
 * @property string $translated_body
 * @property string $provider
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Message $message
 */
#[Fillable([
    'message_id',
    'target_language',
    'source_language',
    'translated_body',
    'provider',
])]
class MessageTranslation extends Model
{
    use HasUuids;

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
