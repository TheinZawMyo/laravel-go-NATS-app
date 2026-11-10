<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TranslateMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'language' => [
                'required',
                'string',
                Rule::in(array_keys((array) config('translation.languages', []))),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('language'))) {
            $this->merge([
                'language' => strtolower(trim($this->input('language'))),
            ]);
        }
    }
}
