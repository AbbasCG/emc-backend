<?php

namespace App\Services;

use App\Exceptions\InstructorEnrollmentConflictException;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\LmsSession;
use App\Models\User;
use App\Models\Workshop;

/**
 * The one rule that limits who may enroll as a learner.
 *
 * Every authenticated, active account is eligible to learn, whatever its organizational role (a volunteer, a finance manager, a
 * super admin, a role added next year). The role is never consulted. What is refused is learning a program you TEACH, and that is
 * decided from the actual teaching assignments, not from holding the instructor role:
 *
 *   - the course (or workshop, which is a course with program_type = workshop) is assigned to the user's instructor profile;
 *   - a scheduled workshop occurrence of the course is;
 *   - a class group of the course is;
 *   - a session of the course (or of one of its workshops) is.
 *
 * So an instructor of course A may enroll in course B; a tech admin who also teaches course D may not enroll in D; a plain
 * instructor profile that teaches nothing may enroll anywhere.
 *
 * Every place that creates a registration or learning-path enrollment calls this before it writes anything. The availability,
 * capacity, payment, placement and duplicate rules stay where they were: this class decides about the person, not the program.
 */
class LearnerEnrollmentGuard
{
    /** @var array<int, int|null> instructor profile id by user id, for the life of the request */
    private array $profiles = [];

    public function instructorProfileId(User $user): ?int
    {
        if (! array_key_exists($user->id, $this->profiles)) {
            $this->profiles[$user->id] = Instructor::query()->where('user_id', $user->id)->value('id');
        }

        return $this->profiles[$user->id];
    }

    /** Whether this user is the instructor of the course/workshop in any of the ways above. */
    public function teachesCourse(User $user, Course $course): bool
    {
        $profileId = $this->instructorProfileId($user);
        if ($profileId === null) {
            return false;
        }

        if ((int) $course->instructor_id === $profileId) {
            return true;
        }

        $workshopIds = Workshop::query()->where('course_id', $course->id)->pluck('id');

        if ($workshopIds->isNotEmpty() && Workshop::query()->whereIn('id', $workshopIds)->where('instructor_id', $profileId)->exists()) {
            return true;
        }

        if (ClassGroup::query()
            ->where('instructor_id', $profileId)
            ->where(fn ($q) => $q->where('course_id', $course->id)->orWhereHas('courses', fn ($c) => $c->whereKey($course->id)))
            ->exists()) {
            return true;
        }

        return LmsSession::query()
            ->where('instructor_id', $profileId)
            ->where(fn ($q) => $q->where('course_id', $course->id)->orWhereIn('workshop_id', $workshopIds))
            ->exists();
    }

    /** Whether this user teaches the learning path itself, or any course in it. */
    public function teachesLearningPath(User $user, LearningPath $path): bool
    {
        $profileId = $this->instructorProfileId($user);
        if ($profileId === null) {
            return false;
        }

        if ((int) $path->instructor_id === $profileId) {
            return true;
        }

        return $path->courses()->get()->contains(fn (Course $course) => $this->teachesCourse($user, $course));
    }

    /**
     * Refuses an instructor who teaches this course. A signed-in user is checked directly; when only an e-mail is known (a guest
     * registration) the e-mail is matched to an account, so the rule cannot be walked around by not signing in.
     *
     * @throws InstructorEnrollmentConflictException
     */
    public function assertCanEnroll(?User $user, Course $course, ?string $email = null): void
    {
        $candidate = $user ?? $this->userByEmail($email);

        if ($candidate !== null && $this->teachesCourse($candidate, $course)) {
            throw new InstructorEnrollmentConflictException($this->courseMessage($course));
        }
    }

    /**
     * The other direction of the same rule: nobody is made the instructor of a program they are enrolled in as a learner.
     *
     * Only an enrollment that grants access refuses it. A payment still in progress does not (the person may simply not finish
     * it), and if it completes after they were assigned, the webhook withholds the enrollment and records a reconciliation item.
     *
     * Call it inside the transaction that writes the assignment: it first locks the course rows (ascending id) so that a concurrent
     * enrollment or payment confirmation for the same course is serialized with it (see GuardsInstructorAssignment).
     *
     * @param  list<int>  $courseIds  the courses (a workshop is a course) whose learners cannot teach here
     * @param  int|null  $pathId  a learning path whose enrolled learners cannot teach it
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function assertMayTeach(int $instructorProfileId, array $courseIds, ?int $pathId = null): void
    {
        // The course rows are locked BEFORE anything is read. MariaDB reads from a snapshot taken at the first plain SELECT of a
        // transaction; if one ran before this lock was granted, the checks below would see the world as it was before the
        // concurrent enrollment / payment confirmation that we just waited for committed, and miss it.
        $courseIds = array_values(array_unique(array_map('intval', $courseIds)));
        sort($courseIds);
        if ($courseIds !== []) {
            Course::query()->whereIn('id', $courseIds)->orderBy('id')->lockForUpdate()->pluck('id');
        }

        if ($pathId !== null) {
            \App\Models\LearningPath::query()->whereKey($pathId)->lockForUpdate()->value('id');
        }

        $userId = Instructor::query()->whereKey($instructorProfileId)->value('user_id');
        if (! $userId) {
            return;
        }

        // Locking reads (current data, not the snapshot), so the answer is right even if a surrounding transaction already read.
        $enrolledInCourse = $courseIds !== [] && \App\Models\Registration::query()
            ->whereIn('course_id', $courseIds)
            ->where('user_id', $userId)
            ->whereIn('status', \App\Enums\RegistrationStatus::activeEnrollment())
            ->lockForUpdate()
            ->value('id') !== null;

        $enrolledInPath = $pathId !== null && \App\Models\LearningPathEnrollment::query()
            ->where('learning_path_id', $pathId)
            ->where('user_id', $userId)
            ->whereIn('status', ['active', 'completed'])
            ->lockForUpdate()
            ->value('id') !== null;

        if ($enrolledInCourse || $enrolledInPath) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'instructor_id' => ['لا يمكن إسناد هذا المدرّب لبرنامج هو مسجّل فيه كمتعلّم. أزل تسجيله أولاً.'],
            ]);
        }
    }

    /**
     * The enrollment side of the same serialization: call it as the FIRST statement of any transaction that GRANTS learner access
     * (an active registration, an active learning-path enrollment). It takes the very locks `assertMayTeach` takes, in the same
     * order (course rows ascending, then the path row), so "assign the instructor" and "enroll / confirm the payment" can never
     * both succeed. Everything read afterwards (the teaching checks) then reads data committed after the lock was granted.
     *
     * Because it must run before any plain read of the transaction, the ids are worked out BEFORE the transaction starts
     * (`pathCourseIds`, `lockScopeFor`).
     *
     * @param  list<int>  $courseIds
     */
    public function lockForEnrollment(array $courseIds, ?int $pathId = null): void
    {
        $courseIds = array_values(array_unique(array_map('intval', $courseIds)));
        sort($courseIds);
        if ($courseIds !== []) {
            Course::query()->whereIn('id', $courseIds)->orderBy('id')->lockForUpdate()->pluck('id');
        }
        if ($pathId !== null) {
            LearningPath::query()->whereKey($pathId)->lockForUpdate()->value('id');
        }
    }

    /**
     * The courses of a learning path, read outside any transaction.
     *
     * @return list<int>
     */
    public function pathCourseIds(int $pathId): array
    {
        return LearningPath::query()->findOrFail($pathId)->courses()->pluck('courses.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * What to lock for a purchasable item (Course, Workshop occurrence or LearningPath).
     *
     * @return array{courses: list<int>, path: int|null}
     */
    public function lockScopeFor(object $purchasable): array
    {
        if ($purchasable instanceof LearningPath) {
            return ['courses' => $this->pathCourseIds((int) $purchasable->id), 'path' => (int) $purchasable->id];
        }
        if ($purchasable instanceof Workshop) {
            return ['courses' => $purchasable->course_id ? [(int) $purchasable->course_id] : [], 'path' => null];
        }

        return ['courses' => [(int) $purchasable->id], 'path' => null];
    }

    /** @throws InstructorEnrollmentConflictException */
    public function assertCanEnrollInWorkshop(User $user, Workshop $workshop): void
    {
        $profileId = $this->instructorProfileId($user);
        $teaches = $profileId !== null && (
            (int) $workshop->instructor_id === $profileId
            || ($workshop->course !== null && $this->teachesCourse($user, $workshop->course))
        );

        if ($teaches) {
            throw new InstructorEnrollmentConflictException('لا يمكنك التسجيل في ورشة أنت المدرّب المسؤول عنها.');
        }
    }

    /** @throws InstructorEnrollmentConflictException */
    public function assertCanEnrollInPath(User $user, LearningPath $path): void
    {
        if ($this->teachesLearningPath($user, $path)) {
            throw new InstructorEnrollmentConflictException('لا يمكنك الالتحاق بمسار تعليمي تقوم بالتدريس فيه.');
        }
    }

    private function userByEmail(?string $email): ?User
    {
        $normalized = mb_strtolower(trim((string) $email));

        return $normalized === '' ? null : User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])->first();
    }

    private function courseMessage(Course $course): string
    {
        return $course->program_type === 'workshop'
            ? 'لا يمكنك التسجيل في ورشة أنت المدرّب المسؤول عنها.'
            : 'لا يمكنك التسجيل في دورة أنت المدرّب المسؤول عنها.';
    }
}
