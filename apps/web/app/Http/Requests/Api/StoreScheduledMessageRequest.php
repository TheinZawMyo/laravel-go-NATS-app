<?php

namespace App\Http\Requests\Api;

use App\Services\ScheduledMessageService;
use Illuminate\Foundation\Http\FormRequest;

class StoreScheduledMessageRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_message_id' => ['required', 'uuid'],
            'body' => ['required', 'string', 'min:1', 'max:'.ScheduledMessageService::MAX_BODY_LENGTH],
            'send_at' => [
                'required',
                'date',
                'after:'.now()->addMinute()->toIso8601String(),
                'before:'.now()->addDays(ScheduledMessageService::MAX_DAYS_AHEAD)->toIso8601String(),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }
}
