<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNotificationBatchRequest;
use App\Http\Resources\NotificationBatchResource;
use App\Models\NotificationBatch;
use App\Services\Notifications\NotificationCreationService;
use App\Services\Notifications\NotificationRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class NotificationBatchController extends Controller
{
    public function store(StoreNotificationBatchRequest $request, NotificationCreationService $notifications): JsonResponse
    {
        $this->logBatchCreationRequest($request, 'notification.batch_creation.request_received');

        $batch = $notifications->createBatch(
            $request->validated('notifications'),
            $request->header('Idempotency-Key'),
            $request->attributes->getString('correlation_id'),
        );

        return (new NotificationBatchResource($batch))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    private function logBatchCreationRequest(StoreNotificationBatchRequest $request, string $event): void
    {
        Log::channel('notifications_creation')->info($event, [
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

    public function show(NotificationBatch $notificationBatch): NotificationBatchResource
    {
        return new NotificationBatchResource($notificationBatch);
    }

    public function retry(NotificationBatch $notificationBatch, NotificationRetryService $retry): JsonResponse
    {
        Log::channel('notifications_creation')->info('notification.batch_retry.request_received', [
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
