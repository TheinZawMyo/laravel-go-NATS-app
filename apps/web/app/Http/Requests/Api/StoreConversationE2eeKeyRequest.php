<?php

namespace App\Http\Requests\Api;

use App\Services\E2eeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConversationE2eeKeyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key_version' => ['required', 'integer', 'min:1', 'max:'.E2eeService::MAX_KEY_VERSION],
            'wraps' => ['required', 'array', 'min:1', 'max:500'],
            'wraps.*.recipient_user_id' => ['required', 'uuid', 'distinct', 'exists:users,id'],
            'wraps.*.algorithm' => ['sometimes', 'string', Rule::in([E2eeService::WRAP_ALGORITHM])],
            'wraps.*.ephemeral_public_key' => ['required', 'string', 'max:2000'],
            'wraps.*.iv' => ['required', 'string', 'max:128'],
            'wraps.*.ciphertext' => ['required', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $wraps = $this->input('wraps');

        if (! is_array($wraps)) {
            return;
        }

        $this->merge([
            'wraps' => array_map(static function (mixed $wrap): mixed {
                if (is_array($wrap) && ! isset($wrap['algorithm'])) {
                    $wrap['algorithm'] = E2eeService::WRAP_ALGORITHM;
                }

                return $wrap;
            }, $wraps),
        ]);
    }
}
