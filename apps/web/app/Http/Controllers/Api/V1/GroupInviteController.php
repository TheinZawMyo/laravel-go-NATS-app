<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreConversationInviteRequest;
use App\Http\Resources\ConversationInviteResource;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\ConversationInvite;
use App\Services\ConversationService;
use App\Services\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class GroupInviteController extends Controller
{
    public function index(
        Request $request,
        Conversation $conversation,
    ): AnonymousResourceCollection {
        $this->authorize('manage', $conversation);

        return ConversationInviteResource::collection(
            ConversationInvite::query()
                ->where('conversation_id', $conversation->id)
                ->orderByDesc('created_at')
                ->get(),
        );
    }

    public function store(
        StoreConversationInviteRequest $request,
        Conversation $conversation,
        GroupService $groups,
    ): JsonResponse {
        $this->authorize('manage', $conversation);

        $expiresAt = $request->validated('expires_at');
        $maxUses = $request->validated('max_uses');

        $invite = $groups->createInvite(
            $request->user(),
            $conversation,
            $expiresAt !== null ? Carbon::parse((string) $expiresAt) : null,
            $maxUses !== null ? (int) $maxUses : null,
        );

        return (new ConversationInviteResource($invite))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(
        Request $request,
        Conversation $conversation,
        ConversationInvite $invite,
        GroupService $groups,
    ): JsonResponse {
        $this->authorize('manage', $conversation);

        $groups->revokeInvite($request->user(), $conversation, $invite);

        return response()->json(status: 204);
    }

    /**
     * Public preview of an invite link: enough to show the join screen before
     * the user commits.
     */
    public function preview(string $token, GroupService $groups): ConversationInviteResource
    {
        return new ConversationInviteResource($groups->preview($token));
    }

    public function redeem(
        Request $request,
        string $token,
        GroupService $groups,
        ConversationService $conversations,
    ): ConversationResource {
        $conversation = $groups->redeem($request->user(), $token);

        return new ConversationResource(
            $conversations->prepareForViewer($request->user(), $conversation),
        );
    }
}
