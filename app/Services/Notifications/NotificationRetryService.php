<?php

namespace App\Services\Notifications;

use App\Enums\NotificationStatus;
use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationRetryService
{
    public function retryNotification(Notification $notification): bool
    {
        if ($notification->status !== NotificationStatus::Failed) {
            Log::channel('notification')->warning('notification.retry.skipped', [
                'notification_id' => $notification->id,
                'batch_id' => $notification->notification_batch_id,
                'correlation_id' => $notification->correlation_id,
                'status' => $notification->status->value,
                'reason' => 'not_failed',
            ]);

            return false;
        }

        DB::transaction(function () use ($notification): void {
            $lockedNotification = Notification::query()
                ->where('id', $notification->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedNotification || $lockedNotification->status !== NotificationStatus::Failed) {
                return;
            }

            $lockedNotification->update([
                'status' => NotificationStatus::Queued,
                'provider_status' => null,
                'failed_at' => null,
            ]);
        });

        $notification->refresh();

        if ($notification->status !== NotificationStatus::Queued) {
            Log::channel('notification')->warning('notification.retry.skipped', [
                'notification_id' => $notification->id,
                'batch_id' => $notification->notification_batch_id,
                'correlation_id' => $notification->correlation_id,
                'status' => $notification->status->value,
                'reason' => 'status_not_queued_after_retry',
            ]);

            return false;
        }

        $this->dispatchDeliveryJob($notification);
        Log::channel('notification')->info('notification.retry.completed', [
            'notification_id' => $notification->id,
            'batch_id' => $notification->notification_batch_id,
            'correlation_id' => $notification->correlation_id,
            'status' => $notification->status->value,
        ]);

        return true;
    }

    public function retryBatch(NotificationBatch $batch): Collection
    {
        $retriedNotifications = collect();

        DB::transaction(function () use ($batch, &$retriedNotifications): void {
            $failedNotifications = Notification::query()
                ->where('notification_batch_id', $batch->id)
                ->where('status', NotificationStatus::Failed->value)
                ->lockForUpdate()
                ->get();

            if ($failedNotifications->isEmpty()) {
                return;
            }

            Notification::query()
                ->whereIn('id', $failedNotifications->pluck('id'))
                ->update([
                    'status' => NotificationStatus::Queued->value,
                    'provider_status' => null,
                    'failed_at' => null,
                ]);

            $retriedNotifications = Notification::query()
                ->whereIn('id', $failedNotifications->pluck('id'))
                ->get();
        });

        $retriedNotifications->each(fn (Notification $notification) => $this->dispatchDeliveryJob($notification));
        Log::channel('notification')->info('notification.batch_retry.completed', [
            'batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'retried_count' => $retriedNotifications->count(),
            'retried_notification_ids' => $retriedNotifications->pluck('id')->values()->all(),
        ]);

        return $retriedNotifications;
    }

    private function dispatchDeliveryJob(Notification $notification): void
    {
        DeliverNotification::dispatch($notification->id)
            ->onQueue($notification->priority->queueName());
    }
}
