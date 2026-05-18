<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';

    public function contentLimit(): int
    {
        return match ($this) {
            self::Sms => 160,
            self::Email => 10000,
            self::Push => 240,
        };
    }
}
