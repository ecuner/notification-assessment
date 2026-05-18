<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Rules\NotificationContentWithinChannelLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'max:255'],
            /**
             * Notification channel.
             * Allowed values: sms, email, push
             *
             * @var string
             *
             * @example sms
             */
            'channel' => ['required', Rule::enum(NotificationChannel::class)],
            'content' => ['required', 'string', 'max:1000', new NotificationContentWithinChannelLimit],
            /**
             * Notification priority.
             * Allowed values: high, normal, low
             *
             * @var string
             *
             * @example high
             */
            'priority' => ['sometimes', Rule::enum(NotificationPriority::class)],
        ];
    }
}
