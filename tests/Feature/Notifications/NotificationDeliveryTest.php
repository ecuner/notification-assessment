<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\NotificationDeliveryAttempt;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    config([
        'notifications.api_key' => 'test-key',
        'notifications.provider_url' => 'https://provider.test/notifications',
        'notifications.rate_limit_per_second' => 100,
        'notifications.rate_limit_release_seconds' => 1,
    ]);

    Queue::fake();
});

test('a cancelled queued notification is not sent by the delivery job', function (): void {
    Http::fake();

    $notification = Notification::factory()->create([
        'status' => NotificationStatus::Queued,
    ]);

    $this->postJson("/api/v1/notifications/{$notification->id}/cancel", [], notificationApiHeaders())
        ->assertOk();

    (new DeliverNotification($notification->id))->handle(app(NotificationDeliveryService::class));

    Http::assertNothingSent();
    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'status' => NotificationStatus::Cancelled->value,
    ]);
});

test('delivery job prevents overlapping notification processing', function (): void {
    $notification = Notification::factory()->create();

    $middleware = (new DeliverNotification($notification->id))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

test('delivery job sends to the provider when the channel rate limit is available', function (): void {
    Http::fake([
        'provider.test/*' => Http::response([
            'messageId' => 'provider-message-1',
            'status' => 'accepted',
        ], 202),
    ]);

    $notification = Notification::factory()->create([
        'channel' => NotificationChannel::Email,
        'status' => NotificationStatus::Queued,
        'correlation_id' => 'delivery-correlation',
    ]);

    (new DeliverNotification($notification->id))->handle(app(NotificationDeliveryService::class));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://provider.test/notifications'
        && $request['to'] === $notification->recipient
        && $request['channel'] === NotificationChannel::Email->value
        && $request['content'] === $notification->content);

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'status' => NotificationStatus::Accepted->value,
        'provider_message_id' => 'provider-message-1',
    ]);
});

test('delivery job does not overwrite a cancellation made during provider delivery', function (): void {
    $notification = Notification::factory()->create([
        'channel' => NotificationChannel::Email,
        'status' => NotificationStatus::Queued,
    ]);

    Http::fake([
        'provider.test/*' => function () use ($notification) {
            Notification::where('id', $notification->id)->update([
                'status' => NotificationStatus::Cancelled->value,
                'cancelled_at' => now(),
            ]);

            return Http::response([
                'message_id' => 'provider-message-after-cancel',
                'status' => 'accepted',
            ], 202);
        },
    ]);

    (new DeliverNotification($notification->id))->handle(app(NotificationDeliveryService::class));

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'status' => NotificationStatus::Cancelled->value,
        'provider_message_id' => null,
    ]);
});

test('delivery job releases itself when the channel rate limit is exhausted', function (): void {
    Http::fake();
    $rateLimitKey = 'notifications:'.NotificationChannel::Sms->value;
    RateLimiter::clear($rateLimitKey);

    for ($attempt = 0; $attempt < 100; $attempt++) {
        RateLimiter::hit($rateLimitKey, 1);
    }

    $notification = Notification::factory()->create([
        'channel' => NotificationChannel::Sms,
        'status' => NotificationStatus::Queued,
    ]);

    $job = (new DeliverNotification($notification->id))->withFakeQueueInteractions();
    $job->handle(app(NotificationDeliveryService::class));

    $job->assertReleased(delay: 1);
    Http::assertNothingSent();
});

test('temporary provider failures are retried with backoff and keep notification queued', function (): void {
    Http::fake([
        'provider.test/*' => Http::response([
            'status' => 'temporary-unavailable',
        ], 500),
    ]);

    $notification = Notification::factory()->create([
        'channel' => NotificationChannel::Sms,
        'status' => NotificationStatus::Queued,
    ]);

    $job = (new DeliverNotification($notification->id))->withFakeQueueInteractions();
    $job->handle(app(NotificationDeliveryService::class));

    $job->assertReleased(delay: 10);
    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'status' => NotificationStatus::Queued->value,
    ]);
});

test('provider 429 respects retry-after header', function (): void {
    Http::fake([
        'provider.test/*' => Http::response([
            'status' => 'rate-limited',
        ], 429, [
            'Retry-After' => '7',
        ]),
    ]);

    $notification = Notification::factory()->create([
        'channel' => NotificationChannel::Sms,
        'status' => NotificationStatus::Queued,
    ]);

    $job = (new DeliverNotification($notification->id))->withFakeQueueInteractions();
    $job->handle(app(NotificationDeliveryService::class));

    $job->assertReleased(delay: 7);
});

test('permanent provider failures mark notification as failed', function (): void {
    Http::fake([
        'provider.test/*' => Http::response([
            'status' => 'bad-request',
        ], 400),
    ]);

    $notification = Notification::factory()->create([
        'channel' => NotificationChannel::Sms,
        'status' => NotificationStatus::Queued,
    ]);

    $job = (new DeliverNotification($notification->id))->withFakeQueueInteractions();
    $job->handle(app(NotificationDeliveryService::class));

    $job->assertNotReleased();
    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'status' => NotificationStatus::Failed->value,
    ]);
});

test('attempt number is derived from persisted delivery attempts', function (): void {
    Http::fake([
        'provider.test/*' => Http::response([
            'status' => 'temporary-unavailable',
        ], 500),
    ]);

    $notification = Notification::factory()->create([
        'channel' => NotificationChannel::Sms,
        'status' => NotificationStatus::Queued,
    ]);

    NotificationDeliveryAttempt::factory()->count(4)->sequence(
        ['attempt_number' => 1, 'notification_id' => $notification->id],
        ['attempt_number' => 2, 'notification_id' => $notification->id],
        ['attempt_number' => 3, 'notification_id' => $notification->id],
        ['attempt_number' => 4, 'notification_id' => $notification->id],
    )->create();

    (new DeliverNotification($notification->id))->handle(app(NotificationDeliveryService::class));

    $this->assertDatabaseHas('notification_delivery_attempts', [
        'notification_id' => $notification->id,
        'attempt_number' => 5,
        'provider_status_code' => 500,
    ]);
});

test('retried job does not send duplicate provider request after notification is accepted', function (): void {
    Http::fake();

    $notification = Notification::factory()->create([
        'status' => NotificationStatus::Accepted,
        'provider_message_id' => 'already-accepted-id',
    ]);

    (new DeliverNotification($notification->id))->handle(app(NotificationDeliveryService::class));

    Http::assertNothingSent();
});

test('channel rate limits are independent for sms email and push', function (): void {
    $providerMessageNumber = 0;

    Http::fake([
        'provider.test/*' => function () use (&$providerMessageNumber) {
            $providerMessageNumber++;

            return Http::response([
                'message_id' => "provider-message-independent-{$providerMessageNumber}",
                'status' => 'accepted',
            ], 202);
        },
    ]);

    foreach (NotificationChannel::cases() as $channel) {
        RateLimiter::clear('notifications:'.$channel->value);
    }

    for ($attempt = 0; $attempt < 100; $attempt++) {
        RateLimiter::hit('notifications:'.NotificationChannel::Sms->value, 1);
    }

    $smsNotification = Notification::factory()->create([
        'channel' => NotificationChannel::Sms,
        'status' => NotificationStatus::Queued,
    ]);
    $emailNotification = Notification::factory()->create([
        'channel' => NotificationChannel::Email,
        'status' => NotificationStatus::Queued,
    ]);
    $pushNotification = Notification::factory()->create([
        'channel' => NotificationChannel::Push,
        'status' => NotificationStatus::Queued,
    ]);

    $smsJob = (new DeliverNotification($smsNotification->id))->withFakeQueueInteractions();
    $smsJob->handle(app(NotificationDeliveryService::class));

    (new DeliverNotification($emailNotification->id))->handle(app(NotificationDeliveryService::class));
    (new DeliverNotification($pushNotification->id))->handle(app(NotificationDeliveryService::class));

    $smsJob->assertReleased(delay: 1);
    Http::assertSentCount(2);
});
