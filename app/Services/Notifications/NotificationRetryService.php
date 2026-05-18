<?php

namespace App\Services\Notifications;

use App\Enums\NotificationStatus;
use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificationRetryService
{
    public function retryNotification(Notification $notification): bool
    {
        if ($notification->status !== NotificationStatus::Failed) {
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
            return false;
        }

        $this->dispatchDeliveryJob($notification);

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

        return $retriedNotifications;
    }

    private function dispatchDeliveryJob(Notification $notification): void
    {
        DeliverNotification::dispatch($notification->id)
            ->onQueue($notification->priority->queueName());
    }
}
