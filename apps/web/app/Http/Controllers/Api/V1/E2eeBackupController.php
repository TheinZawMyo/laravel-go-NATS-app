<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreE2eeBackupRequest;
use App\Http\Resources\E2eeKeyBackupResource;
use App\Services\E2eeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class E2eeBackupController extends Controller
{
    public function show(Request $request, E2eeService $e2ee): JsonResponse
    {
        $backup = $e2ee->backupFor($request->user());

        return response()->json([
            'data' => $backup !== null
                ? (new E2eeKeyBackupResource($backup))->resolve($request)
                : null,
        ]);
    }

    public function store(
        StoreE2eeBackupRequest $request,
        E2eeService $e2ee,
    ): JsonResponse {
        $backup = $e2ee->storeBackup($request->user(), [
            'ciphertext' => (string) $request->validated('ciphertext'),
            'salt' => (string) $request->validated('salt'),
            'iv' => (string) $request->validated('iv'),
            'kdf' => (string) $request->validated('kdf'),
            'iterations' => (int) $request->validated('iterations'),
            'fingerprint' => $request->validated('fingerprint'),
        ]);

        return (new E2eeKeyBackupResource($backup))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
