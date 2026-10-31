<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', Rule::in(['web', 'ios', 'android', 'windows', 'macos', 'linux'])],
            'push_token' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
