<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __invoke(
        Request $request,
        ConversationService $conversations,
        MessageService $messages,
        ?Conversation $conversation = null,
    ): Response {
        $user = $request->user();

        $activeConversation = null;
        $activeMessages = collect();
        $activeHasMore = false;
        $activeNextCursor = null;

        if ($conversation !== null) {
            $this->authorize('view', $conversation);

            $activeConversation = $conversations->prepareForViewer($user, $conversation);

            [$activeMessages, $activeHasMore, $activeNextCursor] = $messages->history(
                $user,
                $conversation,
                null,
                MessageService::DEFAULT_LIMIT,
            );
        }

        return Inertia::render('Chat', [
            'conversations' => $conversations->summariesFor($user)
                ->map(fn (Conversation $item): array => (new ConversationResource($item))->resolve($request))
                ->all(),
            'activeConversation' => $activeConversation !== null
                ? (new ConversationResource($activeConversation))->resolve($request)
                : null,
            'activeMessages' => $activeMessages
                ->map(fn (Message $item): array => (new MessageResource($item))->resolve($request))
                ->all(),
            'activeHasMore' => $activeHasMore,
            'activeNextCursor' => $activeNextCursor,
            'currentUserId' => $user->id,
        ]);
    }
}
