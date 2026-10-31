<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A durable, at-least-once delivery record for a realtime event.
 *
 * Rows are written in the same transaction as the domain change they describe
 * and published to the broker by the Go worker (`apps/worker`).
 *
 * @property string $id
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property Carbon $available_at
 * @property Carbon|null $published_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'aggregate_type',
    'aggregate_id',
    'event_type',
    'payload',
    'attempts',
    'available_at',
    'published_at',
    'last_error',
])]
class OutboxEvent extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
