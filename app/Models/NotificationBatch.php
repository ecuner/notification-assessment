<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use Database\Factories\NotificationBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationBatch extends Model
{
    /** @use HasFactory<NotificationBatchFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'status',
        'total_count',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => NotificationStatus::class,
            'total_count' => 'integer',
        ];
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
