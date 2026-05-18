<?php

namespace App\Services\Notifications;

use App\Enums\NotificationDeliveryOutcome;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

                    return ['outcome' => NotificationDeliveryOutcome::Accepted, 'retry_after' => null];
                }

                if ($this->isTemporaryResponseStatus($response->status())) {
                    $this->markQueuedForRetry($lockedNotification);

                    return [
                        'outcome' => NotificationDeliveryOutcome::Retryable,
                        'retry_after' => $this->retryAfterDelay($response->header('Retry-After')),
                    ];
                }

                $this->markFailed($lockedNotification, $providerStatus);

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
