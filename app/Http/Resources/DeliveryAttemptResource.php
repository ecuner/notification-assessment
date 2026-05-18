<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class DeliveryAttemptResource extends JsonApiResource
{
    public function toId(Request $request): string
    {
        return (string) $this->id;
    }

    public function toType(Request $request): string
    {
        return 'notification-delivery-attempts';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'attemptNumber' => $this->attempt_number,
            'providerStatusCode' => $this->provider_status_code,
            'providerMessageId' => $this->provider_message_id,
            'providerStatus' => $this->provider_status,
            'errorCode' => $this->error_code,
            'errorMessage' => $this->error_message,
            'latencyMs' => $this->latency_ms,
            'correlationId' => $this->correlation_id,
            'attemptedAt' => $this->attempted_at?->toJSON(),
        ];
    }
}
