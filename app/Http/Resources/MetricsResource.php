<?php

namespace App\Http\Resources;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class MetricsResource extends JsonApiResource
{
    public function __construct()
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
        return [
            'queueDepth' => [
                'notifications-high' => null,
                'notifications-normal' => null,
                'notifications-low' => null,
            ],
            'statusCounts' => $this->notificationCountsByStatus(),
            'successRate' => null,
            'failureRate' => null,
            'latency' => [
                'averageMs' => null,
                'p95Ms' => null,
            ],
            'retryCount' => 0,
            'oldestQueuedNotificationAgeSeconds' => null,
        ];
    }

    private function notificationCountsByStatus(): array
    {
        $counts = [];

        foreach (NotificationStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        $storedCounts = Notification::selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach ($storedCounts as $status => $count) {
            $counts[$status] = $count;
        }

        return $counts;
    }
}
