<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'array'],
            /**
             * Filter by notification status.
             * Allowed values: pending, queued, processing, accepted, failed, cancelled
             *
             * @var string
             *
             * @example failed
             */
            'filter.status' => ['nullable', Rule::enum(NotificationStatus::class)],
            /**
             * Filter by notification channel.
             * Allowed values: sms, email, push
             *
             * @var string
             *
             * @example sms
             */
            'filter.channel' => ['nullable', Rule::enum(NotificationChannel::class)],
            /**
             * Filter by notification batch ID.
             *
             * @var string
             *
             * @example 019e3bc4-5fea-72da-a8ca-a0cbbfb6b483
             */
            'filter.batch_id' => ['nullable', 'uuid'],
            /**
             * Filter by correlation ID.
             *
             * @var string
             *
             * @example correlation-1
             */
            'filter.correlation_id' => ['nullable', 'string', 'max:255'],
            /**
             * Filter notifications created on or after this date.
             *
             * @var string
             *
             * @example 2026-05-01
             */
            'filter.date_from' => ['nullable', 'date'],
            /**
             * Filter notifications created on or before this date.
             *
             * @var string
             *
             * @example 2026-05-18
             */
            'filter.date_to' => ['nullable', 'date', 'after_or_equal:filter.date_from'],
            /**
             * Include related resources.
             * Allowed values: deliveryAttempts
             *
             * @var string
             *
             * @example deliveryAttempts
             */
            'include' => ['nullable', 'string', 'in:deliveryAttempts'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string'],
        ];
    }
}
