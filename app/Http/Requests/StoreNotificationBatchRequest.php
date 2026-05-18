<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Rules\NotificationContentWithinChannelLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notifications' => ['required', 'array', 'min:1', 'max:1000'],
            'notifications.*.recipient' => ['required', 'string', 'max:255'],
            'notifications.*.channel' => ['required', Rule::enum(NotificationChannel::class)],
            'notifications.*.content' => ['required', 'string', new NotificationContentWithinChannelLimit],
            'notifications.*.priority' => ['sometimes', Rule::enum(NotificationPriority::class)],
        ];
    }
}
