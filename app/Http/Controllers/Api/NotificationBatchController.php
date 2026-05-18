<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNotificationBatchRequest;
use App\Http\Resources\NotificationBatchResource;
use App\Models\NotificationBatch;
use App\Services\Notifications\NotificationCreationService;
use App\Services\Notifications\NotificationRetryService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class NotificationBatchController extends Controller
{
    #[HeaderParameter('X-Correlation-ID', 'Correlation ID for tracing & debugging purposes. Random will be assigned if you do not pass any.', type: 'string')]
    #[HeaderParameter('X-API-Key', 'API key for authentication.', type: 'string', required: true)]
    #[HeaderParameter('Idempotency-Key', 'Optional idempotency key for safely retrying batch create requests without duplicating notifications.', type: 'string')]
    public function store(StoreNotificationBatchRequest $request, NotificationCreationService $notifications): JsonResponse
    {
        $this->logBatchCreationRequest($request, 'notification.batch_creation.request_received');

        $batch = $notifications->createBatch(
            $request->validated('notifications'),
            $request->header('Idempotency-Key'),
            // Comes from middleware
            $request->attributes->getString('correlation_id'),
        );

        return (new NotificationBatchResource($batch))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    private function logBatchCreationRequest(StoreNotificationBatchRequest $request, string $event): void
    {
        Log::channel('api-calls')->info($event, [
            'correlation_id' => $request->attributes->getString('correlation_id'),
            'idempotency_key' => $request->header('Idempotency-Key'),
            'request' => [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'headers' => $request->headers->all(),
                'query' => $request->query(),
                'payload' => $request->all(),
            ],
        ]);
    }

    #[HeaderParameter('X-Correlation-ID', 'Correlation ID for tracing & debugging purposes. Random will be assigned if you do not pass any.', type: 'string')]
    #[HeaderParameter('X-API-Key', 'API key for authentication.', type: 'string', required: true)]
    public function show(NotificationBatch $notificationBatch): NotificationBatchResource
    {
        // Use NotificationController with batch_id filter to see notifications in a batch
        return new NotificationBatchResource($notificationBatch);
    }

    #[HeaderParameter('X-Correlation-ID', 'Correlation ID for tracing & debugging purposes. Random will be assigned if you do not pass any.', type: 'string')]
    #[HeaderParameter('X-API-Key', 'API key for authentication.', type: 'string', required: true)]
    #[Endpoint(
        title: 'Retry Failed Notifications In Batch',
        description: 'Retriggers only failed notifications within the batch. Returns conflict when the batch has no failed notifications.'
    )]
    public function retry(NotificationBatch $notificationBatch, NotificationRetryService $retry): JsonResponse
    {
        Log::channel('api-calls')->info('notification.batch_retry.request_received', [
            'notification_id' => null,
            'batch_id' => $notificationBatch->id,
            'correlation_id' => request()->attributes->getString('correlation_id'),
            'idempotency_key' => request()->header('Idempotency-Key'),
            'request' => [
                'method' => request()->method(),
                'url' => request()->fullUrl(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'headers' => request()->headers->all(),
                'query' => request()->query(),
                'payload' => request()->all(),
            ],
        ]);

        $retriedNotifications = $retry->retryBatch($notificationBatch);

        if ($retriedNotifications->isEmpty()) {
            return response()->json([
                'message' => 'This batch has no failed notifications to retrigger.',
            ], Response::HTTP_CONFLICT);
        }

        return (new NotificationBatchResource($notificationBatch->refresh()))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
