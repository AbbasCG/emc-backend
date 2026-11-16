<?php

namespace App\Services;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Resolves the free-text `volunteer_requests.desired_department` to a real department.
 *
 * ONE implementation shared by the accepted-volunteers list (the "suggested department" the convert popup is prefilled with) and the
 * convert-to-member action, so the two always agree. Matching tiers, first tier that has a hit wins:
 *   1. name_ar equals the text
 *   2. name_ar contains the text
 *   3. name contains the text
 * Inside a tier an ACTIVE department (status = 'active' and is_active not false) beats an inactive/archived one, then the lowest id.
 *
 * The department list is read once per HTTP request (kept on the request) so listing 200 requests costs one query, not 200.
 */
class VolunteerDepartmentMatcher
{
    private const REQUEST_ATTRIBUTE = 'volunteer_matcher_departments';

    public function match(?string $desired, ?Request $request = null): ?Department
    {
        $desired = trim((string) $desired);
        if ($desired === '') {
            return null;
        }

        $departments = $this->departments($request);

        $tiers = [
            fn (Department $d) => $d->name_ar !== null && $this->same($d->name_ar, $desired),
            fn (Department $d) => $d->name_ar !== null && $this->contains($d->name_ar, $desired),
            fn (Department $d) => $d->name !== null && $this->contains($d->name, $desired),
        ];

        foreach ($tiers as $tier) {
            $hits = $departments->filter($tier);
            if ($hits->isNotEmpty()) {
                return $hits->sortBy([
                    fn (Department $a, Department $b) => (int) $this->isActive($b) <=> (int) $this->isActive($a),
                    fn (Department $a, Department $b) => $a->id <=> $b->id,
                ])->first();
            }
        }

        return null;
    }

    public function isActive(Department $department): bool
    {
        return ($department->status ?? 'active') === 'active' && $department->is_active !== false;
    }

    /** @return Collection<int, Department> */
    private function departments(?Request $request): Collection
    {
        $request ??= request();

        $cached = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if ($cached instanceof Collection) {
            return $cached;
        }

        $all = Department::query()->get(['id', 'name', 'name_ar', 'status', 'is_active', 'leader_id']);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $all);

        return $all;
    }

    private function same(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    private function contains(string $haystack, string $needle): bool
    {
        return mb_stripos($haystack, $needle) !== false;
    }
}
