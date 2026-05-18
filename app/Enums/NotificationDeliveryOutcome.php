<?php

namespace App\Enums;

enum NotificationDeliveryOutcome: string
{
    case Accepted = 'accepted';
    case Failed = 'failed';
    case Retryable = 'retryable';
    case Skipped = 'skipped';
}
