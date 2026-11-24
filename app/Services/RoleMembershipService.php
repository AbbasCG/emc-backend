<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\DepartmentRoleMap;
use Illuminate\Database\Eloquent\Builder;

/**
 * THE one definition of "who is a member of role X", used for the count and the list alike (no second query to drift).
 *
 * An account's role is the single `users.role` column. There is NO additional-roles mechanism (no pivot, no second column), so
 * role_source 'additional' exists in the contract but is never produced. Two ways to be a member:
 *   primary      users.role = the role (the legacy 'teacher' slug counts as 'instructor', as User::basePermissions() does)
 *   specialized  users.role = department_manager AND the user leads (departments.leader_id) an ACTIVE department whose slug maps to the role
 *                (config/department_roles.php, privileged roles never reachable), exactly User::getDepartmentSpecializedRole()
 * Soft-deleted users are excluded (SoftDeletes global scope); inactive accounts are members (the list shows is_active). One row per user.
 */
class RoleMembershipService
{
    /** @return Builder<User> users (id, name, email, is_active, role_source) of the role, name ordered, de-duplicated by construction */
    public function query(Role|string $role): Builder
    {
        $primary = $this->primaryNames($role);
        $marks = implode(',', array_fill(0, count($primary), '?'));

        return $this->base($role)
            ->select(['users.id', 'users.name', 'users.email', 'users.is_active', 'users.role'])
            ->selectRaw("CASE WHEN users.role IN ({$marks}) THEN 'primary' ELSE 'specialized' END AS role_source", $primary)
            ->orderBy('users.name')
            ->orderBy('users.id');
    }

    /** @return Builder<User> the membership predicate alone (no select / order), shared by query() and count() */
    private function base(Role|string $role): Builder
    {
        $primary = $this->primaryNames($role);
        $slugs = $this->leaderSlugsFor($role instanceof Role ? $role->name : $role);

        return User::query()
            ->where(function (Builder $w) use ($primary, $slugs) {
                $w->whereIn('users.role', $primary);
                if ($slugs !== []) {
                    $w->orWhere(fn (Builder $s) => $this->constrainSpecialized($s, $slugs));
                }
            });
    }

    /**
     * Restrict $q to department managers whose SPECIALISED role (User::getDepartmentSpecializedRole(): the mapped slug of the
     * lowest-id ACTIVE department they lead, the first one that maps to a role) is one of $slugs' roles. A leader of two mapped
     * departments therefore acts with exactly one specialised role, never both.
     *
     * @param  Builder<User>  $q
     * @param  array<int, string>  $slugs  department slugs that map to the wanted role(s)
     * @return Builder<User>
     */
    public function constrainSpecialized(Builder $q, array $slugs): Builder
    {
        $mapped = $this->mappedSlugs();

        return $q->where('users.role', 'department_manager')
            ->whereExists(fn ($d) => $d->from('departments')
                ->whereColumn('departments.leader_id', 'users.id')
                ->where('departments.status', 'active')
                ->whereIn('departments.slug', $slugs)
                ->whereNotExists(fn ($e) => $e->from('departments as earlier')
                    ->whereColumn('earlier.leader_id', 'departments.leader_id')
                    ->where('earlier.status', 'active')
                    ->whereIn('earlier.slug', $mapped)
                    ->whereColumn('earlier.id', '<', 'departments.id')));
    }

    /** @return array<int, string> every department slug that maps to a (non-privileged) specialised role */
    public function mappedSlugs(): array
    {
        return array_values(array_filter(
            array_keys((array) config('department_roles.leader_roles', [])),
            fn ($slug) => DepartmentRoleMap::roleForSlug($slug) !== null
        ));
    }

    /** @return array<int, string> */
    private function primaryNames(Role|string $role): array
    {
        $name = $role instanceof Role ? $role->name : $role;

        return $name === 'instructor' ? ['instructor', 'teacher'] : [$name];
    }

    public function count(Role|string $role): int
    {
        return $this->base($role)->count();
    }

    /** @return array<string, int> role name => members, one count per role */
    public function countsFor(iterable $roles): array
    {
        $out = [];
        foreach ($roles as $role) {
            $out[$role instanceof Role ? $role->name : $role] = $this->count($role);
        }

        return $out;
    }

    /** @return array<int, string> department slugs whose active leader acts with $roleName */
    public function leaderSlugsFor(string $roleName): array
    {
        $slugs = [];
        foreach (array_keys((array) config('department_roles.leader_roles', [])) as $slug) {
            if (DepartmentRoleMap::roleForSlug($slug) === $roleName) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }
}
