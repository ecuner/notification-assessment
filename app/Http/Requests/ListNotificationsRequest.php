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
            'filter.status' => ['nullable', Rule::enum(NotificationStatus::class)],
            'filter.channel' => ['nullable', Rule::enum(NotificationChannel::class)],
            'filter.date_from' => ['nullable', 'date'],
            'filter.date_to' => ['nullable', 'date', 'after_or_equal:filter.date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string'],
        ];
    }
}
