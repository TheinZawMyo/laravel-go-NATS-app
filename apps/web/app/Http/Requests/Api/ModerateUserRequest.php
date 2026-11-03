<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ModerateUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:suspend,ban,reactivate'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
