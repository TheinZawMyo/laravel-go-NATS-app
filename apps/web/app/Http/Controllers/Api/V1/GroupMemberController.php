<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConversationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreGroupMembersRequest;
use App\Http\Requests\Api\UpdateGroupMemberRoleRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GroupMemberController extends Controller
{
    public function store(
        StoreGroupMembersRequest $request,
        Conversation $conversation,
        GroupService $groups,
        ConversationService $conversations,
    ): ConversationResource {
        $this->authorize('manage', $conversation);

        $groups->addMembers(
            $request->user(),
            $conversation,
            array_map('strval', (array) $request->validated('user_ids')),
        );

        return new ConversationResource(
            $conversations->prepareForViewer($request->user(), $conversation->refresh()),
        );
    }

    public function destroy(
        Request $request,
        Conversation $conversation,
        string $username,
        GroupService $groups,
        ConversationService $conversations,
    ): ConversationResource {
        $this->authorize('manage', $conversation);

        $groups->removeMember($request->user(), $conversation, $this->findUser($username));

        return new ConversationResource(
            $conversations->prepareForViewer($request->user(), $conversation->refresh()),
        );
    }

    /**
     * A member leaving is not the same as being removed: everyone may leave, but
     * the owner handing over succession keeps the group alive.
     */
    public function leave(
        Request $request,
        Conversation $conversation,
        GroupService $groups,
    ): JsonResponse {
        $this->authorize('leave', $conversation);

        $groups->leave($request->user(), $conversation);

        return response()->json(status: 204);
    }

    public function updateRole(
        UpdateGroupMemberRoleRequest $request,
        Conversation $conversation,
        string $username,
        GroupService $groups,
        ConversationService $conversations,
    ): ConversationResource {
        $this->authorize('assignRoles', $conversation);

        $groups->changeRole(
            $request->user(),
            $conversation,
            $this->findUser($username),
            ConversationRole::from((string) $request->validated('role')),
        );

        return new ConversationResource(
            $conversations->prepareForViewer($request->user(), $conversation->refresh()),
        );
    }

    private function findUser(string $username): User
    {
        return User::query()
            ->where('username', Str::lower($username))
            ->firstOrFail();
    }
}
