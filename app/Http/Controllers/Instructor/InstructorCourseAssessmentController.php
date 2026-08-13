<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseAssessmentSetting;
use App\Services\CourseAssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * إعدادات التقييم — course-level component weights (quizzes vs. final exam)
 * and the quiz-weighting mode. Scoped strictly to the instructor's own course.
 */
class InstructorCourseAssessmentController extends Controller
{
    private function ownedCourse(Request $request, int $courseId): Course
    {
        $profile = $request->user()->instructorProfile;
        abort_unless($profile, 403, 'لا يوجد ملف مدرب مرتبط بحسابك.');

        $course = Course::where('id', $courseId)->where('instructor_id', $profile->id)->first();
        abort_unless($course, 403, 'ليس لديك صلاحية الوصول إلى هذه الدورة.');
        abort_unless((bool) $course->requires_placement_test, 403, 'إعدادات التقييم متاحة فقط لدورات المعهد الإنجليزي التي تتطلب اختبار تحديد المستوى.');

        return $course;
    }

    public function show(Request $request, int $course, CourseAssessmentService $service): JsonResponse
    {
        $courseModel = $this->ownedCourse($request, $course);
        $settings = $service->settingsFor($courseModel);

        return response()->json(['success' => true, 'data' => [
            'course_id' => $courseModel->id,
            'quiz_component_weight' => (float) $settings->quiz_component_weight,
            'final_exam_weight' => (float) $settings->final_exam_weight,
            'quiz_weighting_mode' => $settings->quiz_weighting_mode,
        ]]);
    }

    public function update(Request $request, int $course): JsonResponse
    {
        $courseModel = $this->ownedCourse($request, $course);

        $data = $request->validate([
            'quiz_component_weight' => 'required|numeric|min:0|max:100',
            'final_exam_weight' => 'required|numeric|min:0|max:100',
            'quiz_weighting_mode' => ['required', Rule::in([CourseAssessmentSetting::MODE_EQUAL, CourseAssessmentSetting::MODE_CUSTOM])],
        ]);

        if (! CourseAssessmentSetting::weightsAreValid($data['quiz_component_weight'], $data['final_exam_weight'])) {
            return response()->json([
                'success' => false,
                'message' => 'مجموع وزن الاختبارات القصيرة والاختبار النهائي يجب أن يساوي 100%.',
                'errors' => ['quiz_component_weight' => ['المجموع يجب أن يساوي 100%.']],
            ], 422);
        }

        $settings = CourseAssessmentSetting::updateOrCreate(
            ['course_id' => $courseModel->id],
            $data,
        );

        return response()->json(['success' => true, 'message' => 'تم حفظ إعدادات التقييم.', 'data' => [
            'course_id' => $courseModel->id,
            'quiz_component_weight' => (float) $settings->quiz_component_weight,
            'final_exam_weight' => (float) $settings->final_exam_weight,
            'quiz_weighting_mode' => $settings->quiz_weighting_mode,
        ]]);
    }
}
