<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ConversationMediaRequest;
use App\Http\Resources\MessageAttachmentResource;
use App\Models\Conversation;
use App\Models\MessageAttachment;
use Illuminate\Http\JsonResponse;

/**
 * Shared media gallery for one conversation, newest message first.
 */
class ConversationMediaController extends Controller
{
    public function index(
        ConversationMediaRequest $request,
        Conversation $conversation,
    ): JsonResponse {
        $this->authorize('view', $conversation);

        $beforeSeq = $request->validated('before_seq');
        $limit = (int) ($request->validated('limit') ?? 40);

        $query = MessageAttachment::query()
            ->join('messages', 'messages.id', '=', 'message_attachments.message_id')
            ->where('messages.conversation_id', $conversation->id)
            ->whereNull('messages.deleted_at')
            ->orderByDesc('messages.seq')
            ->select('message_attachments.*', 'messages.seq as message_seq');

        if ($beforeSeq !== null) {
            $query->where('messages.seq', '<', (int) $beforeSeq);
        }

        $rows = $query->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->values();

        return MessageAttachmentResource::collection($items)
            ->additional([
                'meta' => [
                    'has_more' => $hasMore,
                    'next_cursor' => $items->last()?->getAttribute('message_seq'),
                ],
            ])
            ->response();
    }
}
