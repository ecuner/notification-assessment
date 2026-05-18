<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Jobs\DeliverNotification;
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

test('a notification can be created', function (): void {
    $response = $this->postJson('/api/v1/notifications', [
        'recipient' => '+905551234567',
        'channel' => NotificationChannel::Sms->value,
        'content' => 'Your code is 1234.',
        'priority' => NotificationPriority::High->value,
    ], notificationApiHeaders(['X-Correlation-ID' => 'correlation-1']));

    $response
        ->assertAccepted()
        ->assertHeader('X-Correlation-ID', 'correlation-1')
        ->assertJsonPath('data.attributes.recipient', '+905551234567')
        ->assertJsonPath('data.attributes.channel', NotificationChannel::Sms->value)
        ->assertJsonPath('data.attributes.priority', NotificationPriority::High->value)
        ->assertJsonPath('data.attributes.status', NotificationStatus::Queued->value)
        ->assertJsonPath('data.attributes.correlationId', 'correlation-1');

    $this->assertDatabaseHas('notifications', [
        'recipient' => '+905551234567',
        'channel' => NotificationChannel::Sms->value,
        'priority' => NotificationPriority::High->value,
        'status' => NotificationStatus::Queued->value,
        'correlation_id' => 'correlation-1',
    ]);

    Queue::assertPushedOn('notifications-high', DeliverNotification::class);
});

test('notification priority defaults to normal when omitted', function (): void {
    $response = $this->postJson('/api/v1/notifications', [
        'recipient' => '+905551234567',
        'channel' => NotificationChannel::Sms->value,
        'content' => 'Your code is 1234.',
    ], notificationApiHeaders());

    $response
        ->assertAccepted()
        ->assertJsonPath('data.attributes.priority', NotificationPriority::Normal->value);

    $this->assertDatabaseHas('notifications', [
        'recipient' => '+905551234567',
        'priority' => NotificationPriority::Normal->value,
    ]);
});

test('notification creation returns a generated correlation id when header is absent', function (): void {
    $response = $this->postJson('/api/v1/notifications', [
        'recipient' => '+905551230099',
        'channel' => NotificationChannel::Sms->value,
        'content' => 'Generated correlation id test.',
        'priority' => NotificationPriority::Normal->value,
    ], notificationApiHeaders());

    $response->assertAccepted();

    $correlationId = $response->headers->get('X-Correlation-ID');

    expect($correlationId)->not->toBeNull()->and($correlationId)->not->toBe('');

    $this->assertDatabaseHas('notifications', [
        'id' => $response->json('data.id'),
        'correlation_id' => $correlationId,
    ]);
});

test('notification priority controls the delivery queue', function (): void {
    $normalResponse = $this->postJson('/api/v1/notifications', [
        'recipient' => 'user@example.com',
        'channel' => NotificationChannel::Email->value,
        'content' => 'Welcome to the campaign.',
        'priority' => NotificationPriority::Normal->value,
    ], notificationApiHeaders());

    $lowResponse = $this->postJson('/api/v1/notifications', [
        'recipient' => '+905551234568',
        'channel' => NotificationChannel::Push->value,
        'content' => 'Your package is moving.',
        'priority' => NotificationPriority::Low->value,
    ], notificationApiHeaders());

    $normalResponse->assertAccepted();
    $lowResponse->assertAccepted();

    Queue::assertPushedOn('notifications-normal', DeliverNotification::class);
    Queue::assertPushedOn('notifications-low', DeliverNotification::class);
});

test('repeating the same single-create request with idempotency key returns the original notification id', function (): void {
    $payload = [
        'recipient' => '+905551230000',
        'channel' => NotificationChannel::Sms->value,
        'content' => 'Your verification code is 1000.',
        'priority' => NotificationPriority::Normal->value,
    ];

    $firstResponse = $this->postJson('/api/v1/notifications', $payload, notificationApiHeaders([
        'Idempotency-Key' => 'single-key-1',
    ]));
    $secondResponse = $this->postJson('/api/v1/notifications', $payload, notificationApiHeaders([
        'Idempotency-Key' => 'single-key-1',
    ]));

    $firstResponse->assertAccepted();
    $secondResponse
        ->assertAccepted()
        ->assertJsonPath('data.id', $firstResponse->json('data.id'));

    $this->assertDatabaseCount('notifications', 1);
});

test('repeating the same batch-create request with idempotency key returns the original batch id', function (): void {
    $payload = [
        'notifications' => [
            [
                'recipient' => '+905551230001',
                'channel' => NotificationChannel::Sms->value,
                'content' => 'Batch item one.',
                'priority' => NotificationPriority::High->value,
            ],
            [
                'recipient' => 'idempotent@example.com',
                'channel' => NotificationChannel::Email->value,
                'content' => 'Batch item two.',
                'priority' => NotificationPriority::Normal->value,
            ],
        ],
    ];

    $firstResponse = $this->postJson('/api/v1/notification-batches', $payload, notificationApiHeaders([
        'Idempotency-Key' => 'batch-key-1',
    ]));
    $secondResponse = $this->postJson('/api/v1/notification-batches', $payload, notificationApiHeaders([
        'Idempotency-Key' => 'batch-key-1',
    ]));

    $firstResponse->assertAccepted();
    $secondResponse
        ->assertAccepted()
        ->assertJsonPath('data.id', $firstResponse->json('data.id'));

    $this->assertDatabaseCount('notification_batches', 1);
    $this->assertDatabaseCount('notifications', 2);
});

test('reusing an idempotency key with a different payload returns conflict', function (): void {
    $firstPayload = [
        'recipient' => '+905551230002',
        'channel' => NotificationChannel::Sms->value,
        'content' => 'Original payload.',
        'priority' => NotificationPriority::Normal->value,
    ];
    $secondPayload = [
        'recipient' => '+905551230002',
        'channel' => NotificationChannel::Sms->value,
        'content' => 'Changed payload.',
        'priority' => NotificationPriority::Normal->value,
    ];

    $this->postJson('/api/v1/notifications', $firstPayload, notificationApiHeaders([
        'Idempotency-Key' => 'single-key-conflict',
    ]))->assertAccepted();

    $this->postJson('/api/v1/notifications', $secondPayload, notificationApiHeaders([
        'Idempotency-Key' => 'single-key-conflict',
    ]))
        ->assertConflict()
        ->assertJsonPath('message', 'The idempotency key has already been used with a different payload.');
});

test('notification creation validates required enum and content limit fields', function (): void {
    $response = $this->postJson('/api/v1/notifications', [
        'recipient' => '',
        'channel' => 'fax',
        'content' => str_repeat('a', 161),
        'priority' => 'urgent',
    ], notificationApiHeaders());

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recipient', 'channel', 'priority']);

    $response = $this->postJson('/api/v1/notifications', [
        'recipient' => '+905551234567',
        'channel' => NotificationChannel::Sms->value,
        'content' => str_repeat('a', 161),
        'priority' => NotificationPriority::Normal->value,
    ], notificationApiHeaders());

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content']);
});

test('a notification batch can be created', function (): void {
    $response = $this->postJson('/api/v1/notification-batches', [
        'notifications' => [
            [
                'recipient' => '+905551234567',
                'channel' => NotificationChannel::Sms->value,
                'content' => 'Sale starts now.',
                'priority' => NotificationPriority::High->value,
            ],
            [
                'recipient' => 'user@example.com',
                'channel' => NotificationChannel::Email->value,
                'content' => 'Welcome to the campaign.',
            ],
        ],
    ], notificationApiHeaders(['X-Correlation-ID' => 'batch-correlation']));

    $response
        ->assertAccepted()
        ->assertJsonPath('data.attributes.totalCount', 2)
        ->assertJsonPath('data.attributes.statusCounts.queued', 2)
        ->assertJsonPath('data.attributes.correlationId', 'batch-correlation');

    $batchId = $response->json('data.id');

    $this->assertDatabaseHas('notification_batches', [
        'id' => $batchId,
        'total_count' => 2,
        'status' => NotificationStatus::Queued->value,
    ]);

    $this->assertDatabaseCount('notifications', 2);
    Queue::assertPushed(DeliverNotification::class, 2);
});

test('batch creation rejects more than one thousand notifications', function (): void {
    $item = [
        'recipient' => '+905551234567',
        'channel' => NotificationChannel::Sms->value,
        'content' => 'Hello',
        'priority' => NotificationPriority::Normal->value,
    ];

    $response = $this->postJson('/api/v1/notification-batches', [
        'notifications' => array_fill(0, 1001, $item),
    ], notificationApiHeaders());

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['notifications']);
});

test('api endpoints reject missing api keys', function (): void {
    $response = $this->postJson('/api/v1/notifications', [
        'recipient' => '+905551234567',
        'channel' => NotificationChannel::Sms->value,
        'content' => 'Hello',
        'priority' => NotificationPriority::Normal->value,
    ]);

    $response
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

test('notifications can be listed with filters and pagination', function (): void {
    Notification::factory()->create([
        'channel' => NotificationChannel::Sms,
        'status' => NotificationStatus::Accepted,
        'created_at' => now()->subDay(),
    ]);

    Notification::factory()->create([
        'channel' => NotificationChannel::Email,
        'status' => NotificationStatus::Pending,
        'created_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/notifications?filter[status]=accepted&filter[channel]=sms&per_page=1', notificationApiHeaders());

    $response
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.channel', NotificationChannel::Sms->value)
        ->assertJsonPath('data.0.attributes.status', NotificationStatus::Accepted->value)
        ->assertJsonPath('meta.per_page', 1);
});

test('notifications can be listed with batch and correlation filters', function (): void {
    $targetBatch = NotificationBatch::factory()->create();
    $otherBatch = NotificationBatch::factory()->create();

    Notification::factory()->create([
        'notification_batch_id' => $targetBatch->id,
        'correlation_id' => 'target-correlation',
        'status' => NotificationStatus::Accepted,
    ]);
    Notification::factory()->create([
        'notification_batch_id' => $targetBatch->id,
        'correlation_id' => 'other-correlation',
        'status' => NotificationStatus::Accepted,
    ]);
    Notification::factory()->create([
        'notification_batch_id' => $otherBatch->id,
        'correlation_id' => 'target-correlation',
        'status' => NotificationStatus::Accepted,
    ]);

    $response = $this->getJson(
        "/api/v1/notifications?filter[batch_id]={$targetBatch->id}&filter[correlation_id]=target-correlation",
        notificationApiHeaders(),
    );

    $response
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.batchId', $targetBatch->id)
        ->assertJsonPath('data.0.attributes.correlationId', 'target-correlation');
});

test('notifications index can include delivery attempts', function (): void {
    $notification = Notification::factory()->create([
        'status' => NotificationStatus::Accepted,
    ]);

    NotificationDeliveryAttempt::factory()->create([
        'notification_id' => $notification->id,
        'attempt_number' => 1,
        'provider_status_code' => 202,
    ]);

    $response = $this->getJson('/api/v1/notifications?include=deliveryAttempts', notificationApiHeaders());

    $response
        ->assertOk()
        ->assertJsonPath('data.0.attributes.attempts.0.attributes.providerStatusCode', 202);
});

test('notification status can be queried by id', function (): void {
    $notification = Notification::factory()->create([
        'status' => NotificationStatus::Accepted,
        'provider_message_id' => 'provider-message-1',
        'provider_status' => 'accepted',
        'accepted_at' => now(),
    ]);

    NotificationDeliveryAttempt::factory()->create([
        'notification_id' => $notification->id,
        'attempt_number' => 1,
        'provider_status_code' => 202,
        'provider_message_id' => 'provider-message-1',
        'correlation_id' => 'status-correlation',
    ]);

    $response = $this->getJson("/api/v1/notifications/{$notification->id}", notificationApiHeaders());

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $notification->id)
        ->assertJsonPath('data.attributes.status', NotificationStatus::Accepted->value)
        ->assertJsonPath('data.attributes.providerMessageId', 'provider-message-1')
        ->assertJsonPath('data.attributes.attempts.0.attributes.providerStatusCode', 202);
});

test('batch status can be queried by id', function (): void {
    $batch = NotificationBatch::factory()->create([
        'total_count' => 2,
    ]);

    Notification::factory()->create([
        'notification_batch_id' => $batch->id,
        'status' => NotificationStatus::Pending,
    ]);

    Notification::factory()->create([
        'notification_batch_id' => $batch->id,
        'status' => NotificationStatus::Accepted,
    ]);

    $response = $this->getJson("/api/v1/notification-batches/{$batch->id}", notificationApiHeaders());

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $batch->id)
        ->assertJsonPath('data.attributes.totalCount', 2)
        ->assertJsonPath('data.attributes.statusCounts.pending', 1)
        ->assertJsonPath('data.attributes.statusCounts.accepted', 1);
});

test('unknown notification and batch ids return not found', function (): void {
    $this->getJson('/api/v1/notifications/00000000-0000-0000-0000-000000000000', notificationApiHeaders())
        ->assertNotFound();

    $this->getJson('/api/v1/notification-batches/00000000-0000-0000-0000-000000000000', notificationApiHeaders())
        ->assertNotFound();
});

test('pending notifications can be cancelled', function (): void {
    $notification = Notification::factory()->create([
        'status' => NotificationStatus::Pending,
    ]);

    $response = $this->postJson("/api/v1/notifications/{$notification->id}/cancel", [], notificationApiHeaders());

    $response
        ->assertOk()
        ->assertJsonPath('data.attributes.status', NotificationStatus::Cancelled->value);

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'status' => NotificationStatus::Cancelled->value,
    ]);
});

test('accepted notifications cannot be cancelled', function (): void {
    $notification = Notification::factory()->create([
        'status' => NotificationStatus::Accepted,
    ]);

    $response = $this->postJson("/api/v1/notifications/{$notification->id}/cancel", [], notificationApiHeaders());

    $response
        ->assertConflict()
        ->assertJsonPath('message', 'This notification cannot be cancelled.');
});

test('failed notifications cannot be cancelled', function (): void {
    $notification = Notification::factory()->create([
        'status' => NotificationStatus::Failed,
    ]);

    $response = $this->postJson("/api/v1/notifications/{$notification->id}/cancel", [], notificationApiHeaders());

    $response
        ->assertConflict()
        ->assertJsonPath('message', 'This notification cannot be cancelled.');
});

test('cancelling an unknown notification returns not found', function (): void {
    $this->postJson('/api/v1/notifications/00000000-0000-0000-0000-000000000000/cancel', [], notificationApiHeaders())
        ->assertNotFound();
});

test('failed notification can be retriggered', function (): void {
    $notification = Notification::factory()->create([
        'status' => NotificationStatus::Failed,
        'failed_at' => now(),
        'provider_status' => 'provider-error',
    ]);

    $response = $this->postJson("/api/v1/notifications/{$notification->id}/retry", [], notificationApiHeaders());

    $response
        ->assertAccepted()
        ->assertJsonPath('data.attributes.status', NotificationStatus::Queued->value);

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'status' => NotificationStatus::Queued->value,
        'provider_status' => null,
        'failed_at' => null,
    ]);
    Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job): bool => $job->notificationId === $notification->id);
});

test('non-failed notification cannot be retriggered', function (): void {
    $notification = Notification::factory()->create([
        'status' => NotificationStatus::Accepted,
    ]);

    $this->postJson("/api/v1/notifications/{$notification->id}/retry", [], notificationApiHeaders())
        ->assertConflict()
        ->assertJsonPath('message', 'Only failed notifications can be retriggered.');
});

test('unknown notification retrigger returns not found', function (): void {
    $this->postJson('/api/v1/notifications/00000000-0000-0000-0000-000000000000/retry', [], notificationApiHeaders())
        ->assertNotFound();
});

test('failed notifications in a batch can be retriggered', function (): void {
    $batch = NotificationBatch::factory()->create();

    $failedOne = Notification::factory()->create([
        'notification_batch_id' => $batch->id,
        'status' => NotificationStatus::Failed,
        'failed_at' => now(),
        'provider_status' => 'provider-error',
    ]);
    $failedTwo = Notification::factory()->create([
        'notification_batch_id' => $batch->id,
        'status' => NotificationStatus::Failed,
        'failed_at' => now(),
        'provider_status' => 'provider-error',
    ]);
    $accepted = Notification::factory()->create([
        'notification_batch_id' => $batch->id,
        'status' => NotificationStatus::Accepted,
    ]);

    $response = $this->postJson("/api/v1/notification-batches/{$batch->id}/retry", [], notificationApiHeaders());

    $response->assertAccepted();

    $this->assertDatabaseHas('notifications', [
        'id' => $failedOne->id,
        'status' => NotificationStatus::Queued->value,
        'provider_status' => null,
        'failed_at' => null,
    ]);
    $this->assertDatabaseHas('notifications', [
        'id' => $failedTwo->id,
        'status' => NotificationStatus::Queued->value,
        'provider_status' => null,
        'failed_at' => null,
    ]);
    $this->assertDatabaseHas('notifications', [
        'id' => $accepted->id,
        'status' => NotificationStatus::Accepted->value,
    ]);

    Queue::assertPushed(DeliverNotification::class, 2);
});

test('batch without failed notifications cannot be retriggered', function (): void {
    $batch = NotificationBatch::factory()->create();

    Notification::factory()->create([
        'notification_batch_id' => $batch->id,
        'status' => NotificationStatus::Accepted,
    ]);

    $this->postJson("/api/v1/notification-batches/{$batch->id}/retry", [], notificationApiHeaders())
        ->assertConflict()
        ->assertJsonPath('message', 'This batch has no failed notifications to retrigger.');
});
