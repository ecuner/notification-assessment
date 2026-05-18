<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'array'],
            'filter.notification_id' => ['nullable', 'uuid'],
            'filter.batch_id' => ['nullable', 'uuid'],
            'filter.correlation_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
