<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TranslateMessageRequest;
use App\Http\Resources\MessageTranslationResource;
use App\Models\Message;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;

class MessageTranslationController extends Controller
{
    public function store(
        TranslateMessageRequest $request,
        Message $message,
        TranslationService $translations,
    ): JsonResponse {
        $this->authorize('view', $message);

        // A cached row is not "newly created", but the endpoint's result is
        // the same resource either way, so keep the status stable at 200.
        return (new MessageTranslationResource(
            $translations->translate($message, (string) $request->validated('language')),
        ))->response()->setStatusCode(200);
    }
}
