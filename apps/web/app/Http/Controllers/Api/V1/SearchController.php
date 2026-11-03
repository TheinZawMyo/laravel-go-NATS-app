<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SearchRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageSearchResultResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\PresenceService;
use App\Services\SearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchController extends Controller
{
    public function conversations(
        SearchRequest $request,
        SearchService $search,
        ConversationService $conversations,
    ): AnonymousResourceCollection {
        $results = $search->conversations(
            $request->user(),
            (string) $request->validated('q'),
            (int) ($request->validated('limit') ?? SearchService::DEFAULT_LIMIT),
        );

        return ConversationResource::collection(
            $results->map(
                fn (Conversation $conversation): Conversation => $conversations
                    ->prepareForViewer($request->user(), $conversation),
            ),
        );
    }

    public function messages(
        SearchRequest $request,
        SearchService $search,
        PresenceService $presence,
    ): AnonymousResourceCollection {
        $results = $search->messages(
            $request->user(),
            (string) $request->validated('q'),
            $request->validated('conversation_id'),
            (int) ($request->validated('limit') ?? SearchService::DEFAULT_LIMIT),
        );

        $presence->annotate(
            $results->map(fn (Message $message): User => $message->sender),
            $request->user(),
        );

        return MessageSearchResultResource::collection($results);
    }
}
