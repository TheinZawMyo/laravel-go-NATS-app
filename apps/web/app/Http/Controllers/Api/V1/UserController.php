<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SearchUsersRequest;
use App\Http\Resources\PublicUserResource;
use App\Models\User;
use App\Services\ContactService;
use App\Services\PresenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * Search is the entry point for the "add contact" flow, so it never returns
     * the actor or anyone a block hides.
     */
    public function index(SearchUsersRequest $request, ContactService $contacts): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        return PublicUserResource::collection(
            $contacts->search($request->user(), (string) $request->validated('q')),
        );
    }

    public function show(
        Request $request,
        string $username,
        PresenceService $presence,
        ContactService $contacts,
    ): PublicUserResource {
        $user = User::query()
            ->where('username', Str::lower($username))
            ->where('status', AccountStatus::Active)
            ->firstOrFail();

        $this->authorize('view', $user);

        $presence->annotate([$user]);

        $user->setAttribute(
            'relationship',
            $contacts->relationshipsFor($request->user(), [$user])[$user->id]
                ?? ContactService::RELATIONSHIP_NONE,
        );

        return new PublicUserResource($user);
    }
}
