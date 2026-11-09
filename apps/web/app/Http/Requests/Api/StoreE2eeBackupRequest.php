<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreE2eeBackupRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ciphertext' => ['required', 'string', 'max:20000'],
            'salt' => ['required', 'string', 'max:512'],
            'iv' => ['required', 'string', 'max:128'],
            'kdf' => ['sometimes', 'string', Rule::in(['PBKDF2-SHA256'])],
            'iterations' => ['sometimes', 'integer', 'min:100000', 'max:2000000'],
            'fingerprint' => ['nullable', 'string', 'max:128'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'kdf' => $this->input('kdf', 'PBKDF2-SHA256'),
            'iterations' => (int) $this->input('iterations', 600000),
        ]);
    }
}
