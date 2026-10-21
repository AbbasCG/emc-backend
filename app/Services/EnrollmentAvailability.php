<?php

namespace App\Services;

use App\Enums\CourseStatus;
use App\Enums\RegistrationStatus;
use App\Exceptions\EnrollmentUnavailableException;
use App\Models\Course;
use App\Models\Registration;
use App\Support\CourseComputedStatus;

/**
 * Whether a course or workshop (a workshop is a course with program_type = workshop) can take a NEW enrollment.
 *
 * The same four rules for free and paid enrollment, for the public registration and for checkout:
 *   1. published: a draft, cancelled, completed or inactive program takes nobody;
 *   2. not ended: an ended program takes nobody, unless an administrator deliberately kept registration open (the older override);
 *   3. open: `registration_open` is the switch the public pages show, so it is the one that is enforced;
 *   4. not full: `capacity` seats, where a seat is held by an enrollment that grants access or by a payment in progress.
 *      An empty capacity means no limit. The requester's own registration never counts against them (a retry of an abandoned
 *      payment must not be refused for a seat it already holds).
 *
 * It decides about NEW enrollments only. Registrations that already exist, and what they give access to, are never looked at here.
 * Administrators enrolling someone by hand (a management action, audited) and a payment that is already in flight are handled by
 * their own code and deliberately do not call this.
 *
 * The capacity check must run under a lock on the course row inside the transaction that creates the registration; use
 * `lockAndAssertOpen()` there, so two requests for the last seat cannot both succeed.
 */
class EnrollmentAvailability
{
    /** @throws EnrollmentUnavailableException */
    public function assertOpen(Course $course, ?int $requesterId = null, bool $checkCapacity = true): void
    {
        $workshop = $course->program_type === 'workshop';
        $noun = $workshop ? 'الورشة' : 'الدورة';

        if ((string) ($course->status ?? '') !== CourseStatus::PUBLISHED->value) {
            throw new EnrollmentUnavailableException(EnrollmentUnavailableException::UNAVAILABLE, "هذه {$noun} غير متاحة للتسجيل حالياً.");
        }

        if (CourseComputedStatus::blocksPublicEnrollment($course)) {
            throw new EnrollmentUnavailableException(EnrollmentUnavailableException::ENDED, "انتهت هذه {$noun} ولا يمكن التسجيل فيها حالياً.");
        }

        if (($course->registration_open ?? true) === false) {
            throw new EnrollmentUnavailableException(EnrollmentUnavailableException::CLOSED, "التسجيل مغلق لهذه {$noun}.");
        }

        if ($checkCapacity && $this->isFull($course, $requesterId)) {
            throw new EnrollmentUnavailableException(EnrollmentUnavailableException::FULL, "اكتملت المقاعد المتاحة في هذه {$noun}.");
        }
    }

    /**
     * Re-reads the course under a row lock and checks it again. Call it first thing inside the transaction that creates the
     * registration. Returns the locked row.
     *
     * @throws EnrollmentUnavailableException
     */
    public function lockAndAssertOpen(Course $course, ?int $requesterId = null, bool $checkCapacity = true): Course
    {
        $locked = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
        $this->assertOpen($locked, $requesterId, $checkCapacity);

        return $locked;
    }

    public function isFull(Course $course, ?int $requesterId = null): bool
    {
        if ($course->capacity === null || (int) $course->capacity <= 0) {
            return false;
        }

        return $this->seatsTaken($course, $requesterId) >= (int) $course->capacity;
    }

    /**
     * The statuses that hold a seat: an enrollment that grants access, or a payment in progress. Cancelled (and no-show) rows hold
     * none. This is THE definition: enforcement counts with it, and every public seat indicator counts with the same list
     * (`Course::withSeatsTaken()`), so what a visitor sees and what registration allows cannot disagree.
     *
     * @return list<string>
     */
    public static function seatStatuses(): array
    {
        return [...RegistrationStatus::activeEnrollment(), RegistrationStatus::PENDING_PAYMENT->value];
    }

    public function seatsTaken(Course $course, ?int $excludingUserId = null): int
    {
        return Registration::query()
            ->where('course_id', $course->id)
            ->whereIn('status', self::seatStatuses())
            ->when($excludingUserId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('user_id')->orWhere('user_id', '!=', $excludingUserId)))
            ->count();
    }
}
