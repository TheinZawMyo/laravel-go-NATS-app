<?php

namespace App\Http\Requests\Api;

use App\Enums\UserReportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveUserReportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    UserReportStatus::Resolved->value,
                    UserReportStatus::Dismissed->value,
                ]),
            ],
            'action_taken' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
