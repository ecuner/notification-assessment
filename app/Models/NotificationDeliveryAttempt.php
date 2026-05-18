<?php

namespace App\Models;

use Database\Factories\NotificationDeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDeliveryAttempt extends Model
{
    /** @use HasFactory<NotificationDeliveryAttemptFactory> */
    use HasFactory;

    protected $fillable = [
        'notification_id',
        'attempt_number',
        'provider_status_code',
        'provider_message_id',
        'provider_status',
        'error_code',
        'error_message',
        'latency_ms',
        'correlation_id',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'provider_status_code' => 'integer',
            'latency_ms' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('provider_status_code', 202);
    }
}
