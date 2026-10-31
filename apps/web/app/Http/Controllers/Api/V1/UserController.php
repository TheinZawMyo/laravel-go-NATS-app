<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicUserResource;
use App\Models\User;
use App\Services\PresenceService;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function show(string $username, PresenceService $presence): PublicUserResource
    {
        $user = User::query()
            ->where('username', Str::lower($username))
            ->where('status', AccountStatus::Active)
            ->firstOrFail();

        $this->authorize('view', $user);

        $presence->annotate([$user]);

        return new PublicUserResource($user);
    }
}
