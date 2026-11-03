<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePrivacyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PrivacyController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Privacy', [
            'privacy' => [
                'last_seen_visibility' => $user->last_seen_visibility->value,
                'profile_visibility' => $user->profile_visibility->value,
                'contact_requests_policy' => $user->contact_requests_policy->value,
            ],
            'devices' => $user->devices()
                ->latest('last_active_at')
                ->get()
                ->map(fn ($device): array => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'platform' => $device->platform,
                    'last_active_at' => $device->last_active_at,
                    'revoked_at' => $device->revoked_at,
                    'created_at' => $device->created_at,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function update(UpdatePrivacyRequest $request): RedirectResponse
    {
        $request->user()->forceFill($request->validated())->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Privacy settings updated.')]);

        return to_route('privacy.edit');
    }
}
