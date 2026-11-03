<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreUserReportRequest;
use App\Http\Resources\UserReportResource;
use App\Models\UserReport;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserReportController extends Controller
{
    public function index(Request $request, ReportService $reports): AnonymousResourceCollection
    {
        return UserReportResource::collection(
            $reports->forReporter($request->user()),
        );
    }

    public function store(StoreUserReportRequest $request, ReportService $reports): JsonResponse
    {
        $report = $reports->report($request->user(), $request->validated());

        return (new UserReportResource($report))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, UserReport $report): UserReportResource
    {
        abort_unless($report->reporter_id === $request->user()->id, 403);

        return new UserReportResource(
            $report->load(['reportedUser', 'reportedMessage']),
        );
    }
}
