<?php

namespace App\Http\Requests\Api;

use App\Services\E2eeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreE2eeKeyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'public_key' => ['required', 'string', 'max:2000'],
            'algorithm' => ['sometimes', 'string', Rule::in([E2eeService::IDENTITY_ALGORITHM])],
            'fingerprint' => ['required', 'string', 'max:128'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('public_key'))) {
            $this->merge([
                'public_key' => json_encode($this->input('public_key')),
            ]);
        }

        if (! $this->has('algorithm')) {
            $this->merge(['algorithm' => E2eeService::IDENTITY_ALGORITHM]);
        }
    }
}
