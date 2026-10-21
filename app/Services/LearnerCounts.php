<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * How many LEARNERS there are, for the dashboards that mean "people learning on the platform" rather than "accounts with the
 * student role".
 *
 * Learning is not a role, so a volunteer enrolled in a course is a learner like any student. A learner is therefore:
 *   - an account with the `student` role (counted as before, enrolled or not: it is the platform's own learner account), or
 *   - any other account with at least one ACTUAL enrollment (a registration whose status grants access: not pending payment,
 *     not cancelled).
 *
 * "New" follows the same split so no number moves for existing students: a student is new in the month their account was
 * created; anyone else is new in the month of their first actual enrollment.
 *
 * Role-specific USER MANAGEMENT lists (the students screen, the users filter) are not counted here on purpose: there the student
 * role itself is the filter.
 */
class LearnerCounts
{
    /** @return Builder<User> */
    public function query(): Builder
    {
        return User::query()->where(function (Builder $w) {
            $w->where('role', 'student')->orWhereHas('registrations', fn ($r) => $r->whereIn('status', RegistrationStatus::activeEnrollment()));
        });
    }

    public function total(): int
    {
        return $this->query()->count();
    }

    public function active(): int
    {
        return $this->query()->where('is_active', true)->count();
    }

    public function newBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $students = User::query()->where('role', 'student')->whereBetween('created_at', [$from, $to])->count();

        // Non-students: the month of their FIRST actual enrollment (earlier ones must not be inside any earlier month).
        $others = Registration::query()
            ->whereNotNull('user_id')
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->whereHas('user', fn ($u) => $u->where('role', '!=', 'student'))
            ->selectRaw('user_id, MIN(created_at) as first_enrolled_at')
            ->groupBy('user_id')
            ->get()
            ->filter(fn ($row) => $row->first_enrolled_at >= $from->toDateTimeString() && $row->first_enrolled_at <= $to->toDateTimeString())
            ->count();

        return $students + $others;
    }
}
