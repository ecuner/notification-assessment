<?php

namespace App\Services\Notifications;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Support\Facades\Http;
use Throwable;

class NotificationDeliveryService
{
    public function deliver(Notification $notification): void
    {
        $notification = $this->claimForProcessing($notification);

        if (! $notification) {
            return;
        }

        $startedAt = hrtime(true);

        try {
            $providerUrl = config('notifications.provider_url');

            if (! is_string($providerUrl) || $providerUrl === '') {
                throw new \RuntimeException('Notification provider URL is not configured.');
            }

            $response = Http::timeout(config('notifications.provider_timeout_seconds'))
                ->post($providerUrl, [
                    'notification_id' => $notification->id,
                    'recipient' => $notification->recipient,
                    'channel' => $notification->channel->value,
                    'content' => $notification->content,
                    'correlation_id' => $notification->correlation_id,
                ]);

            $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $providerMessageId = $response->json('message_id') ?? $response->json('id');
            $providerStatus = $response->json('status');

            $notification->deliveryAttempts()->create([
                'attempt_number' => $notification->deliveryAttempts()->count() + 1,
                'provider_status_code' => $response->status(),
                'provider_message_id' => $providerMessageId,
                'provider_status' => $providerStatus,
                'latency_ms' => $latencyMs,
                'correlation_id' => $notification->correlation_id,
                'attempted_at' => now(),
            ]);

            if ($response->accepted()) {
                $this->markAccepted($notification, $providerMessageId, $providerStatus);

                return;
            }

            $this->markFailed($notification, $providerStatus);
        } catch (Throwable $exception) {
            $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            $notification->deliveryAttempts()->create([
                'attempt_number' => $notification->deliveryAttempts()->count() + 1,
                'error_code' => 'provider_error',
                'error_message' => $exception->getMessage(),
                'latency_ms' => $latencyMs,
                'correlation_id' => $notification->correlation_id,
                'attempted_at' => now(),
            ]);

            $this->markFailed($notification);
        }
    }

    private function claimForProcessing(Notification $notification): ?Notification
    {
        $claimed = Notification::where('id', $notification->id)
            ->whereIn('status', [
                NotificationStatus::Pending->value,
                NotificationStatus::Queued->value,
            ])
            ->update(['status' => NotificationStatus::Processing->value]);

        if ($claimed !== 1) {
            return null;
        }

        return $notification->refresh();
    }

    private function markAccepted(Notification $notification, mixed $providerMessageId, mixed $providerStatus): void
    {
        Notification::where('id', $notification->id)
            ->where('status', NotificationStatus::Processing->value)
            ->update([
                'status' => NotificationStatus::Accepted->value,
                'provider_message_id' => $providerMessageId,
                'provider_status' => $providerStatus ?? NotificationStatus::Accepted->value,
                'accepted_at' => now(),
            ]);
    }

    private function markFailed(Notification $notification, mixed $providerStatus = null): void
    {
        Notification::where('id', $notification->id)
            ->where('status', NotificationStatus::Processing->value)
            ->update([
                'status' => NotificationStatus::Failed->value,
                'provider_status' => $providerStatus,
                'failed_at' => now(),
            ]);
    }
}
