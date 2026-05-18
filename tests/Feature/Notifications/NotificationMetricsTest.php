<?php

use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\NotificationDeliveryAttempt;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config([
        'notifications.api_key' => 'test-key',
        'notifications.provider_url' => 'https://provider.test/notifications',
        'notifications.rate_limit_per_second' => 100,
        'notifications.rate_limit_release_seconds' => 1,
    ]);

    Queue::fake();
});

test('metrics endpoint returns queue depth status rates and latency fields', function (): void {
    Notification::factory()->create([
        'priority' => NotificationPriority::High,
        'status' => NotificationStatus::Queued,
        'created_at' => now()->subMinutes(5),
    ]);
    Notification::factory()->create([
        'priority' => NotificationPriority::Normal,
        'status' => NotificationStatus::Queued,
    ]);
    Notification::factory()->create([
        'priority' => NotificationPriority::Low,
        'status' => NotificationStatus::Accepted,
    ]);

    $notification = Notification::factory()->create([
        'status' => NotificationStatus::Accepted,
    ]);
    NotificationDeliveryAttempt::factory()->create([
        'notification_id' => $notification->id,
        'attempt_number' => 1,
        'provider_status_code' => 202,
        'latency_ms' => 100,
    ]);
    NotificationDeliveryAttempt::factory()->create([
        'notification_id' => $notification->id,
        'attempt_number' => 2,
        'provider_status_code' => 500,
        'latency_ms' => 300,
    ]);

    $response = $this->getJson('/api/v1/metrics', notificationApiHeaders());

    $response
        ->assertOk()
        ->assertJsonPath('data.attributes.queueDepth.notifications-high', 1)
        ->assertJsonPath('data.attributes.queueDepth.notifications-normal', 1)
        ->assertJsonPath('data.attributes.queueDepth.notifications-low', 0)
        ->assertJsonPath('data.attributes.statusCounts.queued', 2)
        ->assertJsonPath('data.attributes.statusCounts.accepted', 2)
        ->assertJsonPath('data.attributes.failureRate', 50)
        ->assertJsonPath('data.attributes.latency.averageMs', 200)
        ->assertJsonPath('data.attributes.latency.p95Ms', 300)
        ->assertJsonPath('data.attributes.retryCount', 1);
});

test('metrics endpoint reflects accepted and failed delivery attempts', function (): void {
    $notification = Notification::factory()->create([
        'status' => NotificationStatus::Queued,
    ]);

    NotificationDeliveryAttempt::factory()->create([
        'notification_id' => $notification->id,
        'attempt_number' => 1,
        'provider_status_code' => 202,
        'latency_ms' => 50,
    ]);
    NotificationDeliveryAttempt::factory()->create([
        'notification_id' => $notification->id,
        'attempt_number' => 2,
        'provider_status_code' => 503,
        'latency_ms' => 250,
    ]);

    $response = $this->getJson('/api/v1/metrics', notificationApiHeaders());

    $response
        ->assertOk()
        ->assertJsonPath('data.attributes.successRate', 50)
        ->assertJsonPath('data.attributes.failureRate', 50);
});

test('metrics endpoint is unauthorized without api key', function (): void {
    $this->getJson('/api/v1/metrics')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

test('metrics endpoint can be scoped by notification id', function (): void {
    $target = Notification::factory()->create([
        'status' => NotificationStatus::Queued,
        'priority' => NotificationPriority::High,
        'correlation_id' => 'scope-correlation-1',
    ]);
    $other = Notification::factory()->create([
        'status' => NotificationStatus::Queued,
        'priority' => NotificationPriority::Low,
        'correlation_id' => 'scope-correlation-2',
    ]);

    NotificationDeliveryAttempt::factory()->create([
        'notification_id' => $target->id,
        'attempt_number' => 1,
        'provider_status_code' => 202,
        'latency_ms' => 90,
    ]);
    NotificationDeliveryAttempt::factory()->create([
        'notification_id' => $other->id,
        'attempt_number' => 1,
        'provider_status_code' => 500,
        'latency_ms' => 350,
    ]);

    $response = $this->getJson("/api/v1/metrics?filter[notification_id]={$target->id}", notificationApiHeaders());

    $response
        ->assertOk()
        ->assertJsonPath('data.attributes.statusCounts.queued', 1)
        ->assertJsonPath('data.attributes.queueDepth.notifications-high', 1)
        ->assertJsonPath('data.attributes.queueDepth.notifications-low', 0)
        ->assertJsonPath('data.attributes.successRate', 100)
        ->assertJsonPath('data.attributes.failureRate', 0);
});

test('metrics endpoint can be scoped by batch id and correlation id', function (): void {
    $batch = NotificationBatch::factory()->create([
        'correlation_id' => 'batch-scope-correlation',
    ]);

    $first = Notification::factory()->create([
        'notification_batch_id' => $batch->id,
        'correlation_id' => 'batch-scope-correlation',
        'status' => NotificationStatus::Queued,
        'priority' => NotificationPriority::Normal,
    ]);
    $second = Notification::factory()->create([
        'notification_batch_id' => $batch->id,
        'correlation_id' => 'batch-scope-correlation',
        'status' => NotificationStatus::Accepted,
        'priority' => NotificationPriority::Low,
    ]);
    $outside = Notification::factory()->create([
        'correlation_id' => 'outside-correlation',
        'status' => NotificationStatus::Queued,
        'priority' => NotificationPriority::High,
    ]);

    NotificationDeliveryAttempt::factory()->create([
        'notification_id' => $first->id,
        'attempt_number' => 1,
        'provider_status_code' => 202,
        'latency_ms' => 110,
    ]);
    NotificationDeliveryAttempt::factory()->create([
        'notification_id' => $second->id,
        'attempt_number' => 2,
        'provider_status_code' => 503,
        'latency_ms' => 310,
    ]);
    NotificationDeliveryAttempt::factory()->create([
        'notification_id' => $outside->id,
        'attempt_number' => 1,
        'provider_status_code' => 500,
        'latency_ms' => 900,
    ]);

    $response = $this->getJson(
        "/api/v1/metrics?filter[batch_id]={$batch->id}&filter[correlation_id]=batch-scope-correlation",
        notificationApiHeaders(),
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.attributes.statusCounts.queued', 1)
        ->assertJsonPath('data.attributes.statusCounts.accepted', 1)
        ->assertJsonPath('data.attributes.queueDepth.notifications-normal', 1)
        ->assertJsonPath('data.attributes.queueDepth.notifications-high', 0)
        ->assertJsonPath('data.attributes.successRate', 50)
        ->assertJsonPath('data.attributes.failureRate', 50)
        ->assertJsonPath('data.attributes.retryCount', 1);
});
