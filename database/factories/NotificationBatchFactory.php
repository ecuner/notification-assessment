<?php

namespace Database\Factories;

use App\Enums\NotificationStatus;
use App\Models\NotificationBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationBatch>
 */
class NotificationBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => NotificationStatus::Pending,
            'total_count' => fake()->numberBetween(1, 10),
            'correlation_id' => fake()->uuid(),
        ];
    }
}
