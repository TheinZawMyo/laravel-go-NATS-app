<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreConversationE2eeKeyRequest;
use App\Models\Conversation;
use App\Services\E2eeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationE2eeController extends Controller
{
    public function show(
        Request $request,
        Conversation $conversation,
        E2eeService $e2ee,
    ): JsonResponse {
        $this->authorize('view', $conversation);

        return response()->json([
            'data' => $e2ee->stateFor($request->user(), $conversation),
        ]);
    }

    public function storeKey(
        StoreConversationE2eeKeyRequest $request,
        Conversation $conversation,
        E2eeService $e2ee,
    ): JsonResponse {
        $this->authorize('send', $conversation);

        return response()->json([
            'data' => $e2ee->publishConversationKey(
                $request->user(),
                $conversation,
                [
                    'key_version' => (int) $request->validated('key_version'),
                    'wraps' => array_values($request->validated('wraps')),
                ],
            ),
        ]);
    }
}
