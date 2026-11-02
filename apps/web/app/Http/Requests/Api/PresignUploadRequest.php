<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PresignUploadRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowed = (array) config('chat.media.allowed_mime_types', []);
        $maxBytes = (int) config('chat.media.max_bytes');

        return [
            'original_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', Rule::in($allowed)],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.$maxBytes],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mime_type' => __('This file type is not allowed.'),
            'size_bytes' => __('This file exceeds the maximum upload size.'),
        ];
    }
}
