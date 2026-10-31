<?php

namespace App\Http\Requests\Api;

use App\Enums\ConversationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGroupMemberRoleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(array_map(
                fn (ConversationRole $role): string => $role->value,
                ConversationRole::assignable(),
            ))],
        ];
    }
}
