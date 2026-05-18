<?php

namespace App\Http\Resources;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationDeliveryAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Carbon;

class MetricsResource extends JsonApiResource
{
    public function __construct(private readonly array $filters = [])
    {
        parent::__construct((object) []);
    }

    public function toType(Request $request): string
    {
        return 'metrics';
    }

    public function toId(Request $request): string
    {
        return 'current';
    }

    public function toAttributes(Request $request): array
    {
        $queueDepth = $this->queueDepthByPriority($this->notificationsQuery());
        $statusCounts = $this->notificationCountsByStatus($this->notificationsQuery());
        $deliveryAttemptCount = $this->deliveryAttemptsQuery()->count();
        $acceptedAttemptCount = $this->deliveryAttemptsQuery()->successful()->count();
        $failureAttemptCount = $deliveryAttemptCount - $acceptedAttemptCount;

        $latencyValues = $this->deliveryAttemptsQuery()
            ->whereNotNull('latency_ms')
            ->orderBy('latency_ms')
            ->pluck('latency_ms')
            ->map(fn ($latency): int => (int) $latency)
            ->values()
            ->all();

        return [
            'queueDepth' => $queueDepth,
            'statusCounts' => $statusCounts,
            'successRate' => $this->percentage($acceptedAttemptCount, $deliveryAttemptCount),
            'failureRate' => $this->percentage($failureAttemptCount, $deliveryAttemptCount),
            'latency' => $this->latencyMetrics($latencyValues),
            'retryCount' => $this->deliveryAttemptsQuery()->where('attempt_number', '>', 1)->count(),
            'oldestQueuedNotificationAgeSeconds' => $this->oldestQueuedNotificationAgeSeconds($this->notificationsQuery()),
        ];
    }

    private function notificationCountsByStatus(Builder $notifications): array
    {
        $counts = [];

        foreach (NotificationStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        $storedCounts = $notifications
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach ($storedCounts as $status => $count) {
            $counts[$status] = $count;
        }

        return $counts;
    }

    private function queueDepthByPriority(Builder $notifications): array
    {
        return [
            'notifications-high' => (clone $notifications)
                ->where('status', NotificationStatus::Queued->value)
                ->where('priority', 'high')
                ->count(),
            'notifications-normal' => (clone $notifications)
                ->where('status', NotificationStatus::Queued->value)
                ->where('priority', 'normal')
                ->count(),
            'notifications-low' => (clone $notifications)
                ->where('status', NotificationStatus::Queued->value)
                ->where('priority', 'low')
                ->count(),
        ];
    }

    private function percentage(int $value, int $total): ?float
    {
        if ($total === 0) {
            return null;
        }

        return round(($value / $total) * 100, 2);
    }

    private function latencyMetrics(array $latencyValues): array
    {
        if ($latencyValues === []) {
            return [
                'averageMs' => null,
                'p95Ms' => null,
            ];
        }

        $average = (int) round(array_sum($latencyValues) / count($latencyValues));
        $p95Index = max(0, (int) ceil(count($latencyValues) * 0.95) - 1);

        return [
            'averageMs' => $average,
            'p95Ms' => $latencyValues[$p95Index] ?? null,
        ];
    }

    private function oldestQueuedNotificationAgeSeconds(Builder $notifications): ?int
    {
        $oldestQueuedAt = $notifications
            ->where('status', NotificationStatus::Queued->value)
            ->min('created_at');

        if (! is_string($oldestQueuedAt)) {
            return null;
        }

        return Carbon::parse($oldestQueuedAt)->diffInSeconds(now());
    }

    private function notificationsQuery(): Builder
    {
        $query = Notification::query();

        if ($notificationId = $this->filters['notification_id'] ?? null) {
            $query->where('id', $notificationId);
        }

        if ($batchId = $this->filters['batch_id'] ?? null) {
            $query->where('notification_batch_id', $batchId);
        }

        if ($correlationId = $this->filters['correlation_id'] ?? null) {
            $query->where('correlation_id', $correlationId);
        }

        return $query;
    }

    private function deliveryAttemptsQuery(): Builder
    {
        $query = NotificationDeliveryAttempt::query()->whereHas('notification', function (Builder $query): void {
            if ($notificationId = $this->filters['notification_id'] ?? null) {
                $query->where('id', $notificationId);
            }

            if ($batchId = $this->filters['batch_id'] ?? null) {
                $query->where('notification_batch_id', $batchId);
            }

            if ($correlationId = $this->filters['correlation_id'] ?? null) {
                $query->where('correlation_id', $correlationId);
            }
        });

        return $query;
    }
}
