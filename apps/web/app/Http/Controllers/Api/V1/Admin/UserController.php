<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ModerateUserRequest;
use App\Http\Resources\PublicUserResource;
use App\Models\User;
use App\Services\ModerationService;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function moderate(
        ModerateUserRequest $request,
        string $username,
        ModerationService $moderation,
    ): PublicUserResource {
        $target = User::query()
            ->where('username', Str::lower($username))
            ->firstOrFail();

        $action = (string) $request->validated('action');
        $reason = $request->validated('reason');

        $updated = match ($action) {
            'suspend' => $moderation->suspend($request->user(), $target, $reason),
            'ban' => $moderation->ban($request->user(), $target, $reason),
            'reactivate' => $moderation->reactivate($request->user(), $target),
        };

        return new PublicUserResource($updated);
    }
}
