<?php

namespace App\Http\Requests\Settings;

use App\Enums\ContactRequestPolicy;
use App\Enums\LastSeenVisibility;
use App\Enums\ProfileVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePrivacyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'last_seen_visibility' => ['required', Rule::enum(LastSeenVisibility::class)],
            'profile_visibility' => ['required', Rule::enum(ProfileVisibility::class)],
            'contact_requests_policy' => ['required', Rule::enum(ContactRequestPolicy::class)],
        ];
    }
}
