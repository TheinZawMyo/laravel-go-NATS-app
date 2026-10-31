<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ContactRequestIndexRequest;
use App\Http\Requests\Api\StoreContactRequestRequest;
use App\Http\Resources\ContactRequestResource;
use App\Models\ContactRequest;
use App\Models\User;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContactRequestController extends Controller
{
    public function index(
        ContactRequestIndexRequest $request,
        ContactService $contacts,
    ): AnonymousResourceCollection {
        return ContactRequestResource::collection(
            $contacts->requestsFor(
                $request->user(),
                (string) ($request->validated('direction') ?? 'incoming'),
            ),
        );
    }

    public function store(StoreContactRequestRequest $request, ContactService $contacts): JsonResponse
    {
        $target = User::query()
            ->where('username', (string) $request->validated('username'))
            ->firstOrFail();

        $contactRequest = $contacts->sendRequest(
            $request->user(),
            $target,
            $request->validated('message'),
        );

        return (new ContactRequestResource($contactRequest))
            ->response()
            ->setStatusCode(201);
    }

    public function accept(
        Request $request,
        ContactRequest $contactRequest,
        ContactService $contacts,
    ): ContactRequestResource {
        $this->authorize('respond', $contactRequest);

        return new ContactRequestResource(
            $contacts->accept($request->user(), $contactRequest),
        );
    }

    public function reject(
        Request $request,
        ContactRequest $contactRequest,
        ContactService $contacts,
    ): ContactRequestResource {
        $this->authorize('respond', $contactRequest);

        return new ContactRequestResource(
            $contacts->reject($request->user(), $contactRequest),
        );
    }

    public function cancel(
        Request $request,
        ContactRequest $contactRequest,
        ContactService $contacts,
    ): ContactRequestResource {
        $this->authorize('cancel', $contactRequest);

        return new ContactRequestResource(
            $contacts->cancel($request->user(), $contactRequest),
        );
    }
}
