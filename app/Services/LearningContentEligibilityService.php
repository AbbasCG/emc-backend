<?php

namespace App\Services;

use App\Models\CourseMaterial;
use App\Models\CourseModule;
use App\Models\CourseSession;
use App\Models\Lesson;
use App\Models\LmsSession;
use App\Models\User;

/**
 * Canonical eligibility/consistency checks for learning content
 * (CourseModule → Lesson → CourseSession/LmsSession → CourseMaterial).
 *
 * Ticket 7 scope note: this service currently centralizes material-scope
 * consistency (the one confirmed real bug from the Ticket 7 audit — a
 * material's session_id/course_session_id was never cross-checked against
 * its own course_id/class_group_id) and student material visibility.
 * Lesson/module viewing eligibility is not yet centralized here — Lesson
 * has no class_group_id or visibility flag today, so "can view a lesson"
 * reduces to "is enrolled in the lesson's course" via LmsEnrollmentService,
 * which callers already do directly. Extending this service to lessons is
 * listed as a remaining gap, not silently skipped.
 */
class LearningContentEligibilityService
{
    public function __construct(private readonly LmsEnrollmentService $enrollment) {}

    /**
     * Validate that a material's declared course_id/class_group_id/module_id/
     * lesson_id/session_id/course_session_id do not contradict each other.
     * Returns an array of field => error message, empty when consistent.
     *
     * @param array{course_id?:int|null,class_group_id?:int|null,module_id?:int|null,lesson_id?:int|null,session_id?:int|null,course_session_id?:int|null} $data
     */
    public function materialScopeErrors(array $data): array
    {
        $errors = [];
        $courseId = $data['course_id'] ?? null;
        $classGroupId = $data['class_group_id'] ?? null;

        if (! empty($data['module_id'])) {
            $module = CourseModule::find($data['module_id']);
            if ($module && $courseId && (int) $module->course_id !== (int) $courseId) {
                $errors['module_id'] = 'الوحدة المحددة لا تنتمي إلى نفس الدورة.';
            }
        }

        if (! empty($data['lesson_id'])) {
            $lesson = Lesson::with('module')->find($data['lesson_id']);
            if ($lesson) {
                if (! empty($data['module_id']) && (int) $lesson->module_id !== (int) $data['module_id']) {
                    $errors['lesson_id'] = 'الدرس المحدد لا ينتمي إلى الوحدة المحددة.';
                } elseif ($courseId && $lesson->module && (int) $lesson->module->course_id !== (int) $courseId) {
                    $errors['lesson_id'] = 'الدرس المحدد لا ينتمي إلى نفس الدورة.';
                }
            }
        }

        if (! empty($data['session_id'])) {
            $session = LmsSession::find($data['session_id']);
            if ($session) {
                if ($courseId && (int) $session->course_id !== (int) $courseId) {
                    $errors['session_id'] = 'الجلسة المحددة لا تنتمي إلى نفس الدورة.';
                } elseif ($classGroupId && $session->class_group_id && (int) $session->class_group_id !== (int) $classGroupId) {
                    $errors['session_id'] = 'الجلسة المحددة تنتمي إلى فصل مختلف.';
                }
            }
        }

        if (! empty($data['course_session_id'])) {
            $courseSession = CourseSession::find($data['course_session_id']);
            if ($courseSession) {
                if ($courseId && (int) $courseSession->course_id !== (int) $courseId) {
                    $errors['course_session_id'] = 'جلسة المحتوى المحددة لا تنتمي إلى نفس الدورة.';
                } elseif ($classGroupId && $courseSession->class_group_id && (int) $courseSession->class_group_id !== (int) $classGroupId) {
                    $errors['course_session_id'] = 'جلسة المحتوى المحددة تنتمي إلى فصل مختلف.';
                }
            }
        }

        return $errors;
    }

    /**
     * Whether a student may view/download a given material — the same rule
     * CourseMaterialPolicy::isEnrolledStudent() enforces, extended to also
     * reject materials whose linked session/course_session belongs to a
     * different class than the student's own membership.
     */
    public function studentCanViewMaterial(User $user, CourseMaterial $material): bool
    {
        $courseId = $material->course_id;
        if (! $courseId) {
            return false;
        }

        if (! $this->enrollment->isEnrolledInCourse($user, $courseId)) {
            return false;
        }

        $studentClassGroupId = $this->enrollment->classGroupIdsByCourse($user)->get($courseId);

        if ($material->class_group_id && (int) $material->class_group_id !== (int) $studentClassGroupId) {
            return false;
        }

        if ($material->session_id) {
            $session = $material->session ?? LmsSession::find($material->session_id);
            if ($session && $session->class_group_id && (int) $session->class_group_id !== (int) $studentClassGroupId) {
                return false;
            }
        }

        if ($material->course_session_id) {
            $courseSession = $material->courseSession ?? CourseSession::find($material->course_session_id);
            if ($courseSession && $courseSession->class_group_id && (int) $courseSession->class_group_id !== (int) $studentClassGroupId) {
                return false;
            }
        }

        return true;
    }
}
