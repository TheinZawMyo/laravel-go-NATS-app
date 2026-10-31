<?php

namespace App\Http\Requests\Api;

use App\Services\GroupService;
use Illuminate\Foundation\Http\FormRequest;

class StoreGroupRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:1', 'max:'.GroupService::MAX_TITLE_LENGTH],
            'description' => ['nullable', 'string', 'max:'.GroupService::MAX_DESCRIPTION_LENGTH],
            'history_visible_to_new_members' => ['sometimes', 'boolean'],
            'member_ids' => ['nullable', 'array', 'max:'.(GroupService::MAX_MEMBERS - 1)],
            'member_ids.*' => ['uuid', 'exists:users,id'],
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
