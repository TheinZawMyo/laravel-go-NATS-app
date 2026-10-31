<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBlockRequest;
use App\Http\Resources\UserBlockResource;
use App\Models\User;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class BlockController extends Controller
{
    public function index(Request $request, ContactService $contacts): AnonymousResourceCollection
    {
        return UserBlockResource::collection(
            $contacts->blocksFor($request->user()),
        );
    }

    public function store(StoreBlockRequest $request, ContactService $contacts): JsonResponse
    {
        $target = User::query()
            ->where('username', (string) $request->validated('username'))
            ->firstOrFail();

        $this->authorize('block', $target);

        $block = $contacts->block(
            $request->user(),
            $target,
            $request->validated('reason'),
        );

        return (new UserBlockResource($block))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, string $username, ContactService $contacts): JsonResponse
    {
        $target = User::query()
            ->where('username', Str::lower($username))
            ->firstOrFail();

        $this->authorize('block', $target);

        $contacts->unblock($request->user(), $target);

        return response()->json(status: 204);
    }
}
