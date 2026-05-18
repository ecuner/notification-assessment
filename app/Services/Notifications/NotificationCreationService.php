<?php

namespace App\Services\Notifications;

use App\Enums\NotificationStatus;
use App\Jobs\DeliverNotification;
use App\Models\IdempotencyKey;
use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class NotificationCreationService
{
    public function createSingle(array $attributes, ?string $idempotencyKey, string $correlationId): Notification
    {
        $operation = 'notification.create';
        $requestHash = $this->hashPayload($operation, $attributes);

        if ($existing = $this->existingIdempotencyRecord($idempotencyKey, $requestHash)) {
            return $existing->notification()->firstOrFail();
        }

        $notification = DB::transaction(function () use ($attributes, $correlationId, $idempotencyKey, $operation, $requestHash): Notification {
            $notification = Notification::create([
                ...$attributes,
                'status' => NotificationStatus::Queued,
                'correlation_id' => $correlationId,
            ]);

            $this->storeIdempotencyRecord($idempotencyKey, $operation, $requestHash, [
                'notification_id' => $notification->id,
                'response_payload' => [
                    'id' => $notification->id,
                    'status' => $notification->status->value,
                ],
            ]);

            return $notification;
        });

        $this->dispatchDeliveryJob($notification);

        return $notification;
    }

    public function createBatch(array $notifications, ?string $idempotencyKey, string $correlationId): NotificationBatch
    {
        $operation = 'notification-batch.create';
        $requestHash = $this->hashPayload($operation, $notifications);

        if ($existing = $this->existingIdempotencyRecord($idempotencyKey, $requestHash)) {
            return $existing->batch()->firstOrFail();
        }

        $batch = DB::transaction(function () use ($notifications, $correlationId, $idempotencyKey, $operation, $requestHash): NotificationBatch {
            $batch = NotificationBatch::create([
                'status' => NotificationStatus::Queued,
                'total_count' => count($notifications),
                'correlation_id' => $correlationId,
            ]);

            foreach ($notifications as $notification) {
                Notification::create([
                    ...$notification,
                    'notification_batch_id' => $batch->id,
                    'status' => NotificationStatus::Queued,
                    'correlation_id' => $correlationId,
                ]);
            }

            $this->storeIdempotencyRecord($idempotencyKey, $operation, $requestHash, [
                'notification_batch_id' => $batch->id,
                'response_payload' => [
                    'id' => $batch->id,
                    'status' => $batch->status->value,
                    'total_count' => $batch->total_count,
                ],
            ]);

            return $batch;
        });

        $batch->notifications()->get()->each(fn (Notification $notification) => $this->dispatchDeliveryJob($notification));

        return $batch;
    }

    private function existingIdempotencyRecord(?string $idempotencyKey, string $requestHash): ?IdempotencyKey
    {
        if (! $idempotencyKey) {
            return null;
        }

        $record = IdempotencyKey::where('key', $idempotencyKey)->first();

        if (! $record) {
            return null;
        }

        if ($record->request_hash !== $requestHash) {
            throw new HttpResponseException(response()->json([
                'message' => 'The idempotency key has already been used with a different payload.',
            ], 409));
        }

        return $record;
    }

    private function storeIdempotencyRecord(?string $idempotencyKey, string $operation, string $requestHash, array $attributes): void
    {
        if (! $idempotencyKey) {
            return;
        }

        IdempotencyKey::create([
            'key' => $idempotencyKey,
            'operation' => $operation,
            'request_hash' => $requestHash,
            'notification_id' => $attributes['notification_id'] ?? null,
            'notification_batch_id' => $attributes['notification_batch_id'] ?? null,
            'response_payload' => $attributes['response_payload'],
            'status_code' => 202,
        ]);
    }

    private function hashPayload(string $operation, array $payload): string
    {
        $normalized = [
            'operation' => $operation,
            'payload' => $this->sortPayload($payload),
        ];

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function sortPayload(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortPayload($value);
            }
        }

        return $payload;
    }

    private function dispatchDeliveryJob(Notification $notification): void
    {
        DeliverNotification::dispatch($notification->id)
            ->onQueue($notification->priority->queueName());
    }
}
