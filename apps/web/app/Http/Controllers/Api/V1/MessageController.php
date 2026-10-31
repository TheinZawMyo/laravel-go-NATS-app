<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MessageIndexRequest;
use App\Http\Requests\Api\StoreMessageRequest;
use App\Http\Requests\Api\UpdateMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessageController extends Controller
{
    public function index(
        MessageIndexRequest $request,
        Conversation $conversation,
        MessageService $messages,
    ): AnonymousResourceCollection {
        $this->authorize('view', $conversation);

        $beforeSeq = $request->validated('before_seq');
        $limit = $request->validated('limit');

        [$page, $hasMore, $nextCursor] = $messages->history(
            $request->user(),
            $conversation,
            $beforeSeq !== null ? (int) $beforeSeq : null,
            $limit !== null ? (int) $limit : MessageService::DEFAULT_LIMIT,
        );

        return MessageResource::collection($page)->additional([
            'meta' => [
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
        ]);
    }

    public function store(
        StoreMessageRequest $request,
        Conversation $conversation,
        MessageService $messages,
    ): JsonResponse {
        $this->authorize('send', $conversation);

        $validated = $request->validated();

        [$message, $created] = $messages->send(
            $request->user(),
            $conversation,
            [
                'client_message_id' => (string) $validated['client_message_id'],
                'body' => (string) $validated['body'],
                'type' => (string) ($validated['type'] ?? 'text'),
                'reply_to_message_id' => isset($validated['reply_to_message_id'])
                    ? (string) $validated['reply_to_message_id']
                    : null,
            ],
        );

        return (new MessageResource($message))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function update(
        UpdateMessageRequest $request,
        Message $message,
        MessageService $messages,
    ): MessageResource {
        $this->authorize('update', $message);

        return new MessageResource(
            $messages->edit($request->user(), $message, $request->validated('body')),
        );
    }

    public function destroy(Message $message, MessageService $messages): MessageResource
    {
        $this->authorize('delete', $message);

        return new MessageResource($messages->delete($message));
    }
}
