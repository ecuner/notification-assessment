<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListNotificationsRequest;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\Notifications\NotificationCreationService;
use App\Services\Notifications\NotificationRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    public function index(ListNotificationsRequest $request): AnonymousResourceCollection
    {
        $request->validated();

        $notifications = QueryBuilder::for(Notification::class)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('channel'),
                AllowedFilter::exact('batch_id', 'notification_batch_id'),
                AllowedFilter::exact('correlation_id'),
                AllowedFilter::callback('date_from', fn ($query, mixed $value) => $query->where('created_at', '>=', $value)),
                AllowedFilter::callback('date_to', fn ($query, mixed $value) => $query->where('created_at', '<=', $value)),
            )
            ->allowedIncludes('deliveryAttempts')
            ->defaultSort('-created_at')
            ->allowedSorts('created_at', 'updated_at', 'priority')
            ->paginate($request->integer('per_page', 15))
            ->appends($request->query());

        return NotificationResource::collection($notifications);
    }

    public function store(StoreNotificationRequest $request, NotificationCreationService $notifications): JsonResponse
    {
        $this->logCreationRequest($request, 'notification.creation.request_received');

        $notification = $notifications->createSingle(
            $request->validated(),
            $request->header('Idempotency-Key'),
            // Comes from middleware
            $request->attributes->getString('correlation_id'),
        );

        return (new NotificationResource($notification))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    private function logCreationRequest(StoreNotificationRequest $request, string $event): void
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

    public function show(Notification $notification): NotificationResource
    {
        return new NotificationResource($notification->load('deliveryAttempts'));
    }

    public function cancel(Notification $notification): JsonResponse
    {
        if (! $notification->status->canBeCancelled()) {
            return response()->json([
                'message' => 'This notification cannot be cancelled.',
            ], Response::HTTP_CONFLICT);
        }

        $notification->update([
            'status' => NotificationStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        return (new NotificationResource($notification->refresh()))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    public function retry(Notification $notification, NotificationRetryService $retry): JsonResponse
    {
        Log::channel('api-calls')->info('notification.retry.request_received', [
            'notification_id' => $notification->id,
            'batch_id' => $notification->notification_batch_id,
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

        if (! $retry->retryNotification($notification)) {
            return response()->json([
                'message' => 'Only failed notifications can be retriggered.',
            ], Response::HTTP_CONFLICT);
        }

        return (new NotificationResource($notification->refresh()))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
