<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Registration;
use App\Enums\RegistrationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentLessonController extends Controller
{
    /**
     * POST /api/student/lessons/{lesson}/complete
     */
    public function complete(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $request->user();
        $lesson->loadMissing('module.course');

        $courseId = $lesson->module?->course_id;
        if (! $courseId) {
            return response()->json(['success' => false, 'message' => 'Lesson course not found.'], 404);
        }

        $enrolled = Registration::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->exists();

        if (! $enrolled) {
            return response()->json(['success' => false, 'message' => 'You are not enrolled in this course.'], 403);
        }

        // A student may only complete a lesson they could actually view — draft/inactive
        // lessons, or lessons under a deactivated/draft module, cannot be completed by
        // guessing the ID directly.
        if (! $lesson->isVisibleToStudents() || ! $lesson->module?->isVisibleToStudents()) {
            return response()->json(['success' => false, 'message' => 'This lesson is not available.'], 404);
        }

        $row = LessonCompletion::query()->updateOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id],
            ['completed_at' => now()],
        );

        return response()->json([
            'success' => true,
            'message' => 'Lesson marked complete.',
            'data'    => [
                'lesson_id'    => $lesson->id,
                'completed_at' => $row->completed_at?->toIso8601String(),
            ],
        ]);
    }
}
