<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDeviceRequest;
use App\Http\Resources\UserDeviceResource;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DeviceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', UserDevice::class);

        $devices = $request->user()
            ->devices()
            ->latest('last_active_at')
            ->get();

        return UserDeviceResource::collection($devices);
    }

    /**
     * Register (or refresh) the device that receives push notifications.
     *
     * Push tokens are unique across users: when a browser profile signs in with
     * another account, the token moves to that account instead of failing the
     * insert.
     */
    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $this->authorize('create', UserDevice::class);

        $pushToken = $request->validated('push_token');
        $attributes = array_filter(
            [
                'name' => $request->validated('name'),
                'platform' => $request->validated('platform'),
                'last_active_at' => now(),
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        if ($pushToken === null) {
            $device = $request->user()->devices()->create($attributes);
        } else {
            $device = UserDevice::query()->firstOrNew(['push_token' => $pushToken]);
            $device->fill($attributes + ['revoked_at' => null]);
            $device->user()->associate($request->user());
            $device->save();
        }

        return (new UserDeviceResource($device))
            ->response()
            ->setStatusCode($device->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function destroy(UserDevice $device): Response
    {
        $this->authorize('delete', $device);

        if (! $device->isRevoked()) {
            $device->forceFill(['revoked_at' => now()])->save();
        }

        return response()->noContent();
    }
}
