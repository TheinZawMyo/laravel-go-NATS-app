<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreE2eeKeyRequest;
use App\Http\Resources\E2eeIdentityKeyResource;
use App\Services\E2eeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class E2eeKeyController extends Controller
{
    public function show(Request $request, E2eeService $e2ee): JsonResponse
    {
        $key = $e2ee->identityFor($request->user());

        return response()->json([
            'data' => $key !== null
                ? (new E2eeIdentityKeyResource($key))->resolve($request)
                : null,
        ]);
    }

    public function store(
        StoreE2eeKeyRequest $request,
        E2eeService $e2ee,
    ): JsonResponse {
        $key = $e2ee->registerIdentity($request->user(), [
            'public_key' => (string) $request->validated('public_key'),
            'algorithm' => (string) $request->validated('algorithm'),
            'fingerprint' => (string) $request->validated('fingerprint'),
        ]);

        return (new E2eeIdentityKeyResource($key))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
