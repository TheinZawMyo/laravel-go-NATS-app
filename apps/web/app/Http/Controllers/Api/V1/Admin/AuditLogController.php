<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'actor' => ['nullable', 'string', 'max:30'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $logs = AuditLog::query()
            ->with('actor')
            ->when(
                isset($validated['action']),
                fn ($query) => $query->where('action', $validated['action']),
            )
            ->when(
                isset($validated['actor']),
                fn ($query) => $query->whereHas(
                    'actor',
                    fn ($actor) => $actor->where('username', $validated['actor']),
                ),
            )
            ->orderByDesc('created_at')
            ->limit((int) ($validated['limit'] ?? 100))
            ->get();

        return AuditLogResource::collection($logs);
    }
}
