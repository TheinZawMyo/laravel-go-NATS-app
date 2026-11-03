<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DataExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;

class DataExportController extends Controller
{
    public function __invoke(Request $request, DataExportService $export): JsonResponse
    {
        $user = $request->user();
        $filename = 'chat-export-'.$user->username.'-'.now()->format('Y-m-d').'.json';

        return response()
            ->json($export->export($user))
            ->header('Content-Disposition', HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
            ));
    }
}
