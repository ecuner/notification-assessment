<?php

namespace App\Http\Resources;

use App\Enums\NotificationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class NotificationBatchResource extends JsonApiResource
{
    public function toId(Request $request): string
    {
        return $this->id;
    }

    public function toType(Request $request): string
    {
        return 'notification-batches';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'totalCount' => $this->total_count,
            'statusCounts' => $this->statusCounts(),
            'correlationId' => $this->correlation_id,
            'createdAt' => $this->created_at?->toJSON(),
            'updatedAt' => $this->updated_at?->toJSON(),
        ];
    }

    private function statusCounts(): array
    {
        $counts = [];

        foreach (NotificationStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        $storedCounts = $this->notifications()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach ($storedCounts as $status => $count) {
            $counts[$status] = $count;
        }

        return $counts;
    }
}
