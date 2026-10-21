<?php

namespace App\Models\Concerns;

use App\Services\LearnerEnrollmentGuard;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Nobody is made the instructor of something they are enrolled in as a learner, through ANY code path.
 *
 * Used by every model that carries an instructor: a course (its primary instructor), a scheduled workshop, a class group, an LMS
 * session and a learning path. The check runs inside the write itself, in a transaction, after locking the course rows involved
 * (always in ascending id order, and always BEFORE any registration row, the same order the enrollment and payment-confirmation
 * code uses). That makes "assign the instructor" and "enroll or confirm the payment" strictly serial for one course: whichever
 * commits second sees the other's result, so the two can never both succeed.
 *
 * Only an enrollment that grants access refuses an assignment. A payment still in progress does not (the person may simply not
 * finish it): if it completes after the assignment, the payment webhook withholds the enrollment and opens a reconciliation item.
 * Past, cancelled and completed-elsewhere learner records are never touched, and an existing teaching assignment is only checked
 * when its instructor actually changes.
 */
trait GuardsInstructorAssignment
{
    /**
     * The programs whose learners this assignment must exclude.
     *
     * @return array{courses: list<int>, path: int|null}
     */
    abstract protected function instructorConflictScope(): array;

    protected function performInsert(Builder $query)
    {
        return $this->writeUnderInstructorGuard(fn () => parent::performInsert($query));
    }

    protected function performUpdate(Builder $query)
    {
        return $this->writeUnderInstructorGuard(fn () => parent::performUpdate($query));
    }

    private function writeUnderInstructorGuard(Closure $write)
    {
        if (! $this->instructor_id || ! $this->isDirty('instructor_id')) {
            return $write();
        }

        $scope = $this->instructorConflictScope();
        if ($scope['courses'] === [] && $scope['path'] === null) {
            return $write();
        }

        // Three attempts: a deadlock with a concurrent enrollment is retried, not reported.
        return DB::transaction(function () use ($write, $scope) {
            app(LearnerEnrollmentGuard::class)->assertMayTeach((int) $this->instructor_id, $scope['courses'], $scope['path']);

            return $write();
        }, 3);
    }
}
