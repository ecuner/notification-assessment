<?php

namespace App\Enums;

enum NotificationPriority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    public function queueName(): string
    {
        return match ($this) {
            self::High => 'notifications-high',
            self::Normal => 'notifications-normal',
            self::Low => 'notifications-low',
        };
    }
}
