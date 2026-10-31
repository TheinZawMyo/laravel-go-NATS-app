<?php

namespace App\Http\Requests\Api;

use App\Services\GroupService;
use Illuminate\Foundation\Http\FormRequest;

class StoreGroupMembersRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:'.GroupService::MAX_MEMBERS],
            'user_ids.*' => ['uuid', 'exists:users,id'],
        ];
    }
}
