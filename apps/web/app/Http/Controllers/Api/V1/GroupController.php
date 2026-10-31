<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreGroupRequest;
use App\Http\Requests\Api\UpdateGroupRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Services\ConversationService;
use App\Services\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function store(
        StoreGroupRequest $request,
        GroupService $groups,
        ConversationService $conversations,
    ): JsonResponse {
        $memberIds = array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            $request->array('member_ids'),
        ));

        $data = [
            'title' => (string) $request->validated('title'),
            'member_ids' => $memberIds,
        ];

        $description = $request->validated('description');

        if (is_string($description)) {
            $data['description'] = $description;
        }

        if ($request->has('history_visible_to_new_members')) {
            $data['history_visible_to_new_members'] = $request->boolean('history_visible_to_new_members');
        }

        $group = $groups->create($request->user(), $data);

        return (new ConversationResource(
            $conversations->prepareForViewer($request->user(), $group),
        ))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateGroupRequest $request,
        Conversation $conversation,
        GroupService $groups,
        ConversationService $conversations,
    ): ConversationResource {
        $this->authorize('manage', $conversation);

        $groups->updateProfile($request->user(), $conversation, $request->validated());

        return new ConversationResource(
            $conversations->prepareForViewer($request->user(), $conversation->refresh()),
        );
    }

    /**
     * Group members with their roles, for the member management panel.
     */
    public function members(
        Request $request,
        Conversation $conversation,
        GroupService $groups,
        ConversationService $conversations,
    ): ConversationResource {
        $this->authorize('view', $conversation);

        return new ConversationResource(
            $conversations->prepareForViewer($request->user(), $conversation),
        );
    }
}
