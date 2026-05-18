<?php

namespace App\Enums;

enum NotificationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Accepted = 'accepted';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function canBeCancelled(): bool
    {
        return match ($this) {
            self::Pending, self::Queued, self::Processing => true,
            self::Accepted, self::Failed, self::Cancelled => false,
        };
    }
}
