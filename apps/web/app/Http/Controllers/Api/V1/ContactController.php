<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\User;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class ContactController extends Controller
{
    public function index(Request $request, ContactService $contacts): AnonymousResourceCollection
    {
        return ContactResource::collection(
            $contacts->contactsFor($request->user()),
        );
    }

    /**
     * A contact is addressed by username because the address book is keyed by
     * the other person, not by a row id the client has to remember.
     */
    public function update(
        UpdateContactRequest $request,
        string $username,
        ContactService $contacts,
    ): ContactResource {
        $target = $this->findUser($username);

        return new ContactResource(
            $contacts->updateNickname(
                $request->user(),
                $target,
                $request->validated('nickname'),
            ),
        );
    }

    public function destroy(Request $request, string $username, ContactService $contacts): JsonResponse
    {
        $contacts->removeContact($request->user(), $this->findUser($username));

        return response()->json(status: 204);
    }

    private function findUser(string $username): User
    {
        return User::query()
            ->where('username', Str::lower($username))
            ->firstOrFail();
    }
}
