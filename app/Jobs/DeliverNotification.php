<?php

namespace App\Jobs;

use App\Enums\NotificationDeliveryOutcome;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\RateLimiter;

#[Tries(5)]
class DeliverNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    private const MAX_ATTEMPTS = 5;

    private const RETRY_BACKOFF_SECONDS = [
        1 => 10,
        2 => 30,
        3 => 120,
        4 => 300,
        5 => 900,
    ];

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

        $attemptNumber = $notification->deliveryAttempts()->count() + 1;
        $result = $delivery->deliver($notification);

        if (($result['outcome'] ?? null) !== NotificationDeliveryOutcome::Retryable) {
            return;
        }

        if ($attemptNumber >= self::MAX_ATTEMPTS) {
            Notification::where('id', $notification->id)
                ->where('status', NotificationStatus::Queued->value)
                ->update([
                    'status' => NotificationStatus::Failed->value,
                    'failed_at' => now(),
                ]);

            return;
        }

        $retryDelay = $result['retry_after'] ?? self::RETRY_BACKOFF_SECONDS[$attemptNumber] ?? 900;

        $this->release($retryDelay);
    }
}
