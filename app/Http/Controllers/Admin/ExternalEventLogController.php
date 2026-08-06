<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExternalEventLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExternalEventLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = ExternalEventLog::query()
            ->when($request->source, fn ($q) => $q->where('source', $request->source))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->event_type, fn ($q) => $q->where('event_type', $request->event_type))
            ->latest()
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data'    => $logs->items(),
            'meta'    => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }
}
