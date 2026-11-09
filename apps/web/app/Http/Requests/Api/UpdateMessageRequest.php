<?php

namespace App\Http\Requests\Api;

use App\Services\E2eeService;
use App\Services\MessageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required_without:encryption', 'nullable', 'string', 'max:'.MessageService::MAX_BODY_LENGTH],
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
            'body.required_without' => __('A message body is required.'),
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
