<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ResolveUserReportRequest;
use App\Http\Resources\UserReportResource;
use App\Models\UserReport;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:open,resolved,dismissed'],
            'reason' => ['nullable', 'string', 'max:64'],
        ]);

        return UserReportResource::collection(
            $reports->forModeration($filters),
        );
    }

    public function resolve(
        ResolveUserReportRequest $request,
        UserReport $report,
        ReportService $reports,
    ): UserReportResource {
        return new UserReportResource(
            $reports->resolve(
                $request->user(),
                $report,
                UserReportStatus::from((string) $request->validated('status')),
                $request->validated('note'),
                $request->validated('action_taken'),
            ),
        );
    }
}
