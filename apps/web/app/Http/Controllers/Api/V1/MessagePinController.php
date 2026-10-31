<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessagePinResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessagePinController extends Controller
{
    public function index(Conversation $conversation, MessageService $messages): AnonymousResourceCollection
    {
        $this->authorize('view', $conversation);

        return MessagePinResource::collection($messages->pins($conversation));
    }

    public function store(
        Request $request,
        Message $message,
        MessageService $messages,
    ): JsonResponse {
        $conversation = $message->conversation;

        $this->authorize('pin', $conversation);

        $pin = $messages->pin($request->user(), $conversation, $message);

        return (new MessagePinResource($pin))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Message $message, MessageService $messages): JsonResponse
    {
        $this->authorize('pin', $message->conversation);

        $messages->unpin($message->conversation, $message);

        return response()->json(status: 204);
    }
}
