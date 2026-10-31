<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConversationMuteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_muted' => ['required', 'boolean'],
            'until' => ['nullable', 'date', 'after:now'],
        ];
    }
}
