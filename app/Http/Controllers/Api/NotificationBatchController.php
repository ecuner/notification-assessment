<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNotificationBatchRequest;
use App\Http\Resources\NotificationBatchResource;
use App\Models\NotificationBatch;
use App\Services\Notifications\NotificationCreationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class NotificationBatchController extends Controller
{
    public function store(StoreNotificationBatchRequest $request, NotificationCreationService $notifications): JsonResponse
    {
        $batch = $notifications->createBatch(
            $request->validated('notifications'),
            $request->header('Idempotency-Key'),
            $request->attributes->getString('correlation_id'),
        );

        return (new NotificationBatchResource($batch))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(NotificationBatch $notificationBatch): NotificationBatchResource
    {
        return new NotificationBatchResource($notificationBatch);
    }
}
