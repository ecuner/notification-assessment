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
use Illuminate\Support\Facades\Log;
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
            $this->logJobEvent('notification.job.skipped', $notification, 'skipped');

            return;
        }

        $this->logJobEvent('notification.job.started', $notification, 'started');

        $rateLimitKey = "notifications:{$notification->channel->value}";
        $maxAttempts = config('notifications.rate_limit_per_second');

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            $this->logJobEvent('notification.job.rate_limited', $notification, 'rate_limited');
            $this->release(config('notifications.rate_limit_release_seconds'));

            return;
        }

        RateLimiter::hit($rateLimitKey, 1);

        $attemptNumber = $this->attempts();
        $result = $delivery->deliver($notification);

        if (($result['outcome'] ?? null) !== NotificationDeliveryOutcome::Retryable) {
            $notification->refresh();
            $this->logJobEvent('notification.job.finished', $notification, $result['outcome']?->value ?? 'finished');
            $this->logBatchCompletionIfCompleted($notification);

            return;
        }

        if ($attemptNumber >= self::MAX_ATTEMPTS) {
            Notification::where('id', $notification->id)
                ->where('status', NotificationStatus::Queued->value)
                ->update([
                    'status' => NotificationStatus::Failed->value,
                    'failed_at' => now(),
                ]);

            // We don't fail the Laravel job, because we're using Notification's status & NotificationDeliveryAttempts
            // to keep track of things. Job states can be messy
            $notification->refresh();
            $this->logJobEvent('notification.job.finished', $notification, NotificationDeliveryOutcome::Failed->value);
            $this->logBatchCompletionIfCompleted($notification);

            return;
        }

        $retryDelay = $result['retry_after'] ?? self::RETRY_BACKOFF_SECONDS[$attemptNumber] ?? 900;
        $this->logJobEvent('notification.job.retry_scheduled', $notification, NotificationDeliveryOutcome::Retryable->value, [
            'retry_delay_seconds' => $retryDelay,
        ]);

        $this->release($retryDelay);
    }

    private function logJobEvent(string $event, ?Notification $notification, string $outcomeStatus, array $extra = []): void
    {
        Log::channel('notifications_job')->info($event, array_merge([
            'notification_id' => $notification?->id ?? $this->notificationId,
            'batch_id' => $notification?->notification_batch_id,
            'correlation_id' => $notification?->correlation_id,
            'job_id' => $this->job?->getJobId(),
            'outcome_status' => $outcomeStatus,
        ], $extra));
    }

    private function logBatchCompletionIfCompleted(Notification $notification): void
    {
        if (! $notification->notification_batch_id) {
            return;
        }

        $remaining = Notification::query()
            ->where('notification_batch_id', $notification->notification_batch_id)
            ->whereIn('status', [
                NotificationStatus::Pending->value,
                NotificationStatus::Queued->value,
                NotificationStatus::Processing->value,
            ])
            ->count();

        if ($remaining !== 0) {
            return;
        }

        Log::channel('notifications_job')->info('notification.batch.delivery_completed', [
            'notification_id' => $notification->id,
            'batch_id' => $notification->notification_batch_id,
            'correlation_id' => $notification->correlation_id,
            'job_id' => $this->job?->getJobId(),
            'outcome_status' => 'batch_delivery_completed',
        ]);
    }
}
