<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class NotificationResource extends JsonApiResource
{
    public function toId(Request $request): string
    {
        return $this->id;
    }

    public function toType(Request $request): string
    {
        return 'notifications';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'batchId' => $this->notification_batch_id,
            'recipient' => $this->recipient,
            'channel' => $this->channel->value,
            'content' => $this->content,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'providerMessageId' => $this->provider_message_id,
            'providerStatus' => $this->provider_status,
            'correlationId' => $this->correlation_id,
            'acceptedAt' => $this->accepted_at?->toJSON(),
            'cancelledAt' => $this->cancelled_at?->toJSON(),
            'failedAt' => $this->failed_at?->toJSON(),
            'createdAt' => $this->created_at?->toJSON(),
            'updatedAt' => $this->updated_at?->toJSON(),
            'attempts' => DeliveryAttemptResource::collection($this->whenLoaded('deliveryAttempts')),
        ];
    }
}
