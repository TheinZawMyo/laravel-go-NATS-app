<?php

namespace App\Http\Requests\Api;

use App\Enums\MessageType;
use App\Services\MessageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_message_id' => ['required', 'uuid'],
            'type' => ['sometimes', 'string', Rule::in([MessageType::Text->value])],
            'body' => ['required_without:attachment_ids', 'nullable', 'string', 'max:'.MessageService::MAX_BODY_LENGTH],
            'attachment_ids' => ['sometimes', 'array', 'max:10'],
            'attachment_ids.*' => ['uuid', 'exists:message_attachments,id'],
            'reply_to_message_id' => ['nullable', 'uuid', 'exists:messages,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required_without' => __('A message body or at least one attachment is required.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge([
                'body' => trim($this->input('body')),
            ]);
        }
    }
}
