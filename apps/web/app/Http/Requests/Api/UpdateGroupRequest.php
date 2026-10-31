<?php

namespace App\Http\Requests\Api;

use App\Services\GroupService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGroupRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:1', 'max:'.GroupService::MAX_TITLE_LENGTH],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.GroupService::MAX_DESCRIPTION_LENGTH],
            'avatar_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'history_visible_to_new_members' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge([
                'title' => trim($this->input('title')),
            ]);
        }
    }
}
