<?php

namespace App\Models;

use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One idempotency truth source for both individual & batch notifications. Less clutter

class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'operation',
        'request_hash',
        'notification_id',
        'notification_batch_id',
        'response_payload', // Mostly for debugging, no meaningful usage (i.e. stores Notification ID & status)
        'status_code',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'status_code' => 'integer',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(NotificationBatch::class, 'notification_batch_id');
    }
}
