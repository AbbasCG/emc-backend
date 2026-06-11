<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\LmsSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionAttendanceController extends Controller
{
    public function show(Request $request, LmsSession $session): JsonResponse
    {
        $this->authorize('updateAttendance', $session);

        $session->load('attendances.user');

        return response()->json([
            'success' => true,
            'data'    => [
                'session_id'  => $session->id,
                'attendances' => AttendanceResource::collection($session->attendances),
            ],
        ]);
    }

    public function update(Request $request, LmsSession $session): JsonResponse
    {
        $this->authorize('updateAttendance', $session);

        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.user_id' => ['required', 'exists:users,id'],
            'rows.*.status' => ['required', 'in:present,absent,late,excused'],
            'rows.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($session, $validated) {
            foreach ($validated['rows'] as $row) {
                Attendance::query()->updateOrCreate(
                    [
                        'session_id' => $session->id,
                        'user_id' => $row['user_id'],
                    ],
                    [
                        'status' => $row['status'],
                        'notes' => $row['notes'] ?? null,
                        'checked_in_at' => in_array($row['status'], ['present', 'late'], true) ? now() : null,
                    ]
                );
            }
        });

        $session->load('attendances.user');

        return response()->json([
            'success' => true,
            'message' => 'Attendance updated successfully.',
            'data' => [
                'session_id' => $session->id,
                'attendances' => AttendanceResource::collection($session->attendances),
            ],
        ]);
    }
}
