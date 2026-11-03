<?php

namespace App\Http\Requests\Api;

use App\Enums\ReportReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserReportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['nullable', 'string', 'required_without:message_id', 'exists:users,username'],
            'message_id' => ['nullable', 'uuid', 'required_without:username', 'exists:messages,id'],
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'details' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => strtolower(trim($this->input('username')))]);
        }
    }
}
