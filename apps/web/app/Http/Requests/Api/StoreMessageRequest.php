<?php

namespace App\Http\Requests\Api;

use App\Enums\MessageType;
use App\Services\E2eeService;
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
            'body' => ['required_without_all:attachment_ids,encryption', 'nullable', 'string', 'max:'.MessageService::MAX_BODY_LENGTH],
            'attachment_ids' => ['sometimes', 'array', 'max:10'],
            'attachment_ids.*' => ['uuid', 'exists:message_attachments,id'],
            'reply_to_message_id' => ['nullable', 'uuid', 'exists:messages,id'],
            'encryption' => ['nullable', 'array'],
            'encryption.algorithm' => ['required_with:encryption', 'string', Rule::in([E2eeService::MESSAGE_ALGORITHM])],
            'encryption.key_version' => ['required_with:encryption', 'integer', 'min:1', 'max:'.E2eeService::MAX_KEY_VERSION],
            'encryption.iv' => ['required_with:encryption', 'string', 'max:128'],
            'encryption.ciphertext' => ['required_with:encryption', 'string', 'max:12000'],
            'encryption.aad' => ['required_with:encryption', 'string', 'max:256'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required_without_all' => __('A message body or at least one attachment is required.'),
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
