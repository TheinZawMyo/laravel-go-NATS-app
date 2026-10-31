<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdvanceReadCursorRequest;
use App\Http\Requests\Api\StoreDirectConversationRequest;
use App\Http\Requests\Api\UpdateConversationArchiveRequest;
use App\Http\Requests\Api\UpdateConversationMuteRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class ConversationController extends Controller
{
    /**
     * Mute is stored as an end timestamp, so "forever" needs a far future date
     * rather than a second boolean column.
     */
    private const MUTE_FOREVER_YEARS = 100;

    public function index(Request $request, ConversationService $conversations): AnonymousResourceCollection
    {
        return ConversationResource::collection(
            $conversations->summariesFor($request->user()),
        );
    }

    public function storeDirect(
        StoreDirectConversationRequest $request,
        ConversationService $conversations,
    ): ConversationResource {
        $other = User::query()
            ->where('username', $request->validated('username'))
            ->where('status', AccountStatus::Active)
            ->firstOrFail();

        $conversation = $conversations->openDirect($request->user(), $other);

        return new ConversationResource(
            $conversations->prepareForViewer($request->user(), $conversation),
        );
    }

    public function show(
        Request $request,
        Conversation $conversation,
        ConversationService $conversations,
    ): ConversationResource {
        $this->authorize('view', $conversation);

        return new ConversationResource(
            $conversations->prepareForViewer($request->user(), $conversation),
        );
    }

    public function read(
        AdvanceReadCursorRequest $request,
        Conversation $conversation,
        ConversationService $conversations,
    ): JsonResponse {
        $this->authorize('read', $conversation);

        $lastReadSeq = $conversations->markRead(
            $request->user(),
            $conversation,
            (int) $request->validated('seq'),
        );

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->id,
                'last_read_seq' => $lastReadSeq,
            ],
        ]);
    }

    public function mute(
        UpdateConversationMuteRequest $request,
        Conversation $conversation,
        ConversationService $conversations,
    ): ConversationResource {
        $this->authorize('view', $conversation);

        $isMuted = (bool) $request->validated('is_muted');
        $until = null;

        if ($isMuted) {
            $requested = $request->validated('until');
            $until = $requested !== null
                ? Carbon::parse((string) $requested)
                : Carbon::now()->addYears(self::MUTE_FOREVER_YEARS);
        }

        return new ConversationResource(
            $conversations->setMuted($request->user(), $conversation, $until),
        );
    }

    public function archive(
        UpdateConversationArchiveRequest $request,
        Conversation $conversation,
        ConversationService $conversations,
    ): ConversationResource {
        $this->authorize('view', $conversation);

        return new ConversationResource(
            $conversations->setArchived(
                $request->user(),
                $conversation,
                (bool) $request->validated('archived'),
            ),
        );
    }
}
