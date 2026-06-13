<?php

namespace App\Http\Controllers\Student;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\CourseNote;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentNoteController extends Controller
{
    /**
     * GET /api/student/courses/{courseId}/notes
     *
     * Return the authenticated student's private notes for this course.
     * Returns empty content when no notes exist yet.
     */
    public function show(Request $request, int $courseId): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            Registration::where('user_id', $user->id)
                ->where('course_id', $courseId)
                ->whereIn('status', RegistrationStatus::activeEnrollment())
                ->exists(),
            403,
            'غير مسجّل في هذه الدورة.'
        );

        $note = CourseNote::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'content'    => $note?->content ?? '',
                'updated_at' => $note?->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * PUT /api/student/courses/{courseId}/notes
     *
     * Create or update the student's private notes for this course.
     */
    public function upsert(Request $request, int $courseId): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            Registration::where('user_id', $user->id)
                ->where('course_id', $courseId)
                ->whereIn('status', RegistrationStatus::activeEnrollment())
                ->exists(),
            403,
            'غير مسجّل في هذه الدورة.'
        );

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:50000'],
        ]);

        $note = CourseNote::updateOrCreate(
            ['user_id' => $user->id, 'course_id' => $courseId],
            ['content' => $validated['content']]
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'content'    => $note->content,
                'updated_at' => $note->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
