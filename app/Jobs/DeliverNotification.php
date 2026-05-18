<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\RateLimiter;

#[Tries(10)]
class DeliverNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct(public string $notificationId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("notification:{$this->notificationId}"))
                ->releaseAfter(config('notifications.overlap_release_seconds'))
                ->expireAfter(config('notifications.overlap_expire_seconds')),
        ];
    }

    public function handle(NotificationDeliveryService $delivery): void
    {
        $notification = Notification::find($this->notificationId);

        if (! $notification || $notification->status->isConcluded()) {
            return;
        }

        $rateLimitKey = "notifications:{$notification->channel->value}";
        $maxAttempts = config('notifications.rate_limit_per_second');

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            $this->release(config('notifications.rate_limit_release_seconds'));

            return;
        }

        RateLimiter::hit($rateLimitKey, 1);

        $delivery->deliver($notification);
    }
}
