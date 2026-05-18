<?php

namespace App\Rules;

use App\Enums\NotificationChannel;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class NotificationContentWithinChannelLimit implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $channelValue = str($attribute)->contains('.')
            ? data_get($this->data, str($attribute)->beforeLast('.')->append('.channel')->value())
            : data_get($this->data, 'channel');

        $channel = NotificationChannel::tryFrom((string) $channelValue);

        if (! $channel) {
            return;
        }

        if (mb_strlen((string) $value) > $channel->contentLimit()) {
            $fail("The :attribute may not be greater than {$channel->contentLimit()} characters for {$channel->value} notifications.");
        }
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }
}
