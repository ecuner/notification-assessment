<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\NotificationDeliveryAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDeliveryAttempt>
 */
class NotificationDeliveryAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'attempt_number' => 1,
            'provider_status_code' => fake()->randomElement([202, 500]),
            'provider_message_id' => fake()->uuid(),
            'provider_status' => 'accepted',
            'latency_ms' => fake()->numberBetween(20, 800),
            'correlation_id' => fake()->uuid(),
            'attempted_at' => now(),
        ];
    }
}
