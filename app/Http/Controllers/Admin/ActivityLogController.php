<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $logs = ActivityLog::with('user')
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->action, fn ($q) => $q->where('action', 'like', '%' . $request->action . '%'))
            ->when($request->model_type, fn ($q) => $q->where('model_type', $request->model_type))
            ->latest()
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data'    => ActivityLogResource::collection($logs)->response()->getData(true),
        ]);
    }
}
