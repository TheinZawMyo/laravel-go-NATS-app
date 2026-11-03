<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreScheduledMessageRequest;
use App\Models\Conversation;
use App\Models\ScheduledMessage;
use App\Services\ScheduledMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduledMessageController extends Controller
{
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        return response()->json([
            'data' => ScheduledMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('sender_id', $request->user()->id)
                ->where('status', 'pending')
                ->orderBy('send_at')
                ->get(['id', 'conversation_id', 'client_message_id', 'body', 'send_at', 'status']),
        ]);
    }

    public function store(
        StoreScheduledMessageRequest $request,
        Conversation $conversation,
        ScheduledMessageService $scheduledMessages,
    ): JsonResponse {
        $this->authorize('send', $conversation);
        $data = $request->validated();
        $scheduled = $scheduledMessages->schedule(
            $request->user(),
            $conversation,
            $data['client_message_id'],
            $data['body'],
            now()->parse($data['send_at']),
        );

        return response()->json(['data' => $scheduled], 201);
    }

    public function destroy(
        Request $request,
        ScheduledMessage $scheduledMessage,
        ScheduledMessageService $scheduledMessages,
    ): JsonResponse {
        if ($scheduledMessage->sender_id !== $request->user()->id) {
            abort(404);
        }

        if (! $scheduledMessages->cancel($request->user(), $scheduledMessage)) {
            abort(409, 'Only a pending scheduled message can be cancelled.');
        }

        return response()->json(status: 204);
    }
}
