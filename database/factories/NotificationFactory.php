<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipient' => '+90555'.fake()->numerify('#######'),
            'channel' => fake()->randomElement(NotificationChannel::cases()),
            'content' => fake()->sentence(),
            'priority' => fake()->randomElement(NotificationPriority::cases()),
            'status' => NotificationStatus::Pending,
            'correlation_id' => fake()->uuid(),
        ];
    }
}
