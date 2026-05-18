<?php

namespace App\Services\Notifications;

use App\Enums\NotificationDeliveryOutcome;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationDeliveryService
{
    public function deliver(Notification $notification): array
    {
        $notification = $this->claimForProcessing($notification);

        if (! $notification) {
            return ['outcome' => NotificationDeliveryOutcome::Skipped, 'retry_after' => null];
        }

        $startedAt = hrtime(true);
        $providerUrl = config('notifications.provider_url');

        if (! is_string($providerUrl) || $providerUrl === '') {
            $this->markFailed($notification);
            Log::channel('notifications_delivery')->error('notification.delivery.provider_url_missing', [
                'notification_id' => $notification->id,
                'batch_id' => $notification->notification_batch_id,
                'correlation_id' => $notification->correlation_id,
                'status' => NotificationStatus::Failed->value,
            ]);

            return ['outcome' => NotificationDeliveryOutcome::Failed, 'retry_after' => null];
        }

        try {
            $response = Http::timeout(config('notifications.provider_timeout_seconds'))
                ->post($providerUrl, [
                    'to' => $notification->recipient,
                    'channel' => $notification->channel->value,
                    'content' => $notification->content,
                ]);

            $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $providerMessageId = $response->json('messageId') ?? $response->json('message_id') ?? $response->json('id');
            $providerStatus = $response->json('status');

            return DB::transaction(function () use (
                $notification,
                $response,
                $providerMessageId,
                $providerStatus,
                $latencyMs,
            ): array {
                $lockedNotification = Notification::where('id', $notification->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedNotification) {
                    return ['outcome' => NotificationDeliveryOutcome::Skipped, 'retry_after' => null];
                }

                $attemptNumber = $this->nextAttemptNumber($lockedNotification);
                $lockedNotification->deliveryAttempts()->create([
                    'attempt_number' => $attemptNumber,
                    'provider_status_code' => $response->status(),
                    'provider_message_id' => $providerMessageId,
                    'provider_status' => $providerStatus,
                    'latency_ms' => $latencyMs,
                    'correlation_id' => $lockedNotification->correlation_id,
                    'attempted_at' => now(),
                ]);

                if ($response->accepted()) {
                    $this->markAccepted($lockedNotification, $providerMessageId, $providerStatus);
                    $this->logDeliveryOutcome($lockedNotification, $attemptNumber, $response, NotificationDeliveryOutcome::Accepted);

                    return ['outcome' => NotificationDeliveryOutcome::Accepted, 'retry_after' => null];
                }

                if ($this->isTemporaryResponseStatus($response->status())) {
                    $this->markQueuedForRetry($lockedNotification);
                    $this->logDeliveryOutcome($lockedNotification, $attemptNumber, $response, NotificationDeliveryOutcome::Retryable);

                    return [
                        'outcome' => NotificationDeliveryOutcome::Retryable,
                        'retry_after' => $this->retryAfterDelay($response->header('Retry-After')),
                    ];
                }

                $this->markFailed($lockedNotification, $providerStatus);
                $this->logDeliveryOutcome($lockedNotification, $attemptNumber, $response, NotificationDeliveryOutcome::Failed);

                return ['outcome' => NotificationDeliveryOutcome::Failed, 'retry_after' => null];
            });
        } catch (ConnectionException $exception) {
            $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            return DB::transaction(function () use ($notification, $exception, $latencyMs): array {
                $lockedNotification = Notification::where('id', $notification->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedNotification) {
                    return ['outcome' => NotificationDeliveryOutcome::Skipped, 'retry_after' => null];
                }

                $attemptNumber = $this->nextAttemptNumber($lockedNotification);
                $lockedNotification->deliveryAttempts()->create([
                    'attempt_number' => $attemptNumber,
                    'error_code' => 'provider_connection_error',
                    'error_message' => $exception->getMessage(),
                    'latency_ms' => $latencyMs,
                    'correlation_id' => $lockedNotification->correlation_id,
                    'attempted_at' => now(),
                ]);

                $this->markQueuedForRetry($lockedNotification);
                $this->logDeliveryException($lockedNotification, $attemptNumber, $exception, NotificationDeliveryOutcome::Retryable);

                return ['outcome' => NotificationDeliveryOutcome::Retryable, 'retry_after' => null];
            });
        } catch (Throwable $exception) {
            $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            return DB::transaction(function () use ($notification, $exception, $latencyMs): array {
                $lockedNotification = Notification::where('id', $notification->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedNotification) {
                    return ['outcome' => NotificationDeliveryOutcome::Skipped, 'retry_after' => null];
                }

                $attemptNumber = $this->nextAttemptNumber($lockedNotification);
                $lockedNotification->deliveryAttempts()->create([
                    'attempt_number' => $attemptNumber,
                    'error_code' => 'provider_error',
                    'error_message' => $exception->getMessage(),
                    'latency_ms' => $latencyMs,
                    'correlation_id' => $lockedNotification->correlation_id,
                    'attempted_at' => now(),
                ]);

                $this->markFailed($lockedNotification);
                $this->logDeliveryException($lockedNotification, $attemptNumber, $exception, NotificationDeliveryOutcome::Failed);

                return ['outcome' => NotificationDeliveryOutcome::Failed, 'retry_after' => null];
            });
        }
    }

    private function claimForProcessing(Notification $notification): ?Notification
    {
        return DB::transaction(function () use ($notification): ?Notification {
            $lockedNotification = Notification::where('id', $notification->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedNotification) {
                return null;
            }

            if (! in_array($lockedNotification->status, [NotificationStatus::Pending, NotificationStatus::Queued], true)) {
                return null;
            }

            $lockedNotification->update(['status' => NotificationStatus::Processing]);

            return $lockedNotification->refresh();
        });
    }

    private function markQueuedForRetry(Notification $notification): void
    {
        Notification::where('id', $notification->id)
            ->where('status', NotificationStatus::Processing->value)
            ->update(['status' => NotificationStatus::Queued->value]);
    }

    private function nextAttemptNumber(Notification $notification): int
    {
        return ((int) $notification->deliveryAttempts()->max('attempt_number')) + 1;
    }

    private function logDeliveryOutcome(
        Notification $notification,
        int $attemptNumber,
        HttpResponse $response,
        NotificationDeliveryOutcome $outcome,
    ): void {
        Log::channel('notifications_delivery')->info('notification.delivery.outcome', [
            'notification_id' => $notification->id,
            'batch_id' => $notification->notification_batch_id,
            'attempt_number' => $attemptNumber,
            'outcome' => $outcome->value,
            'status' => $notification->status->value,
            'provider_status_code' => $response->status(),
            'provider_message_id' => $response->json('messageId') ?? $response->json('message_id') ?? $response->json('id'),
            'correlation_id' => $notification->correlation_id,
        ]);
    }

    private function logDeliveryException(
        Notification $notification,
        int $attemptNumber,
        Throwable $exception,
        NotificationDeliveryOutcome $outcome,
    ): void {
        Log::channel('notifications_delivery')->warning('notification.delivery.exception', [
            'notification_id' => $notification->id,
            'batch_id' => $notification->notification_batch_id,
            'attempt_number' => $attemptNumber,
            'outcome' => $outcome->value,
            'status' => $notification->status->value,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'correlation_id' => $notification->correlation_id,
        ]);
    }

    // Converts Retry-After header from 429 request into seconds
    private function retryAfterDelay(?string $retryAfter): ?int
    {
        if (! is_string($retryAfter) || $retryAfter === '') {
            return null;
        }

        if (is_numeric($retryAfter)) {
            return max(1, (int) $retryAfter);
        }

        $retryAt = strtotime($retryAfter);

        if ($retryAt === false) {
            return null;
        }

        return max(1, $retryAt - now()->getTimestamp());
    }

    private function isTemporaryResponseStatus(int $statusCode): bool
    {
        return $statusCode === 408
            || $statusCode === 429
            || ($statusCode >= 500 && $statusCode <= 599);
    }

    private function markAccepted(Notification $notification, mixed $providerMessageId, mixed $providerStatus): void
    {
        Notification::where('id', $notification->id)
            ->where('status', NotificationStatus::Processing->value)
            ->update([
                'status' => NotificationStatus::Accepted->value,
                'provider_message_id' => $providerMessageId,
                'provider_status' => is_string($providerStatus) && $providerStatus !== '' ? $providerStatus : NotificationStatus::Accepted->value,
                'accepted_at' => now(),
            ]);
    }

    private function markFailed(Notification $notification, mixed $providerStatus = null): void
    {
        Notification::where('id', $notification->id)
            ->where('status', NotificationStatus::Processing->value)
            ->update([
                'status' => NotificationStatus::Failed->value,
                'provider_status' => is_string($providerStatus) ? $providerStatus : null,
                'failed_at' => now(),
            ]);
    }
}
