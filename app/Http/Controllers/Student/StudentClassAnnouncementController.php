<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\ClassAnnouncement;
use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentClassAnnouncementController extends Controller
{
    // ─── GET /api/student/classes/{group}/announcements ──────────────────────
    // Published announcements for a class — only visible to its active members.

    public function index(Request $request, ClassGroup $group): JsonResponse
    {
        $user = $request->user();

        $isActiveMember = ClassGroupStudent::where('class_group_id', $group->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
            ->exists();

        abort_if(! $isActiveMember, 403, 'أنت لست عضوًا نشطًا في هذا الصف.');

        $announcements = ClassAnnouncement::where('class_group_id', $group->id)
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->get(['id', 'title', 'body', 'priority', 'published_at']);

        return response()->json(['success' => true, 'data' => $announcements]);
    }
}
