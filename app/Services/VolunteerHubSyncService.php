<?php

namespace App\Services;

use App\Models\Department;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerProfile;
use App\Models\VolunteerRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps the Operations Volunteer Hub (`volunteer_profiles`) in step with every place a volunteer can exist.
 *
 * The hub lists `volunteer_profiles` rows, so a volunteer without one is invisible there and cannot hold Impact Points. A volunteer
 * is anyone who is
 *   (i)   a user with role `volunteer`;
 *   (ii)  the account linked from a `team_profiles` row with member_type = 'volunteer';
 *   (iii) the account of a converted volunteer request (`volunteer_requests.converted_member_id` -> `team_profiles.user_id`).
 *
 * ensureProfiles() finds the volunteers that have no profile IN SQL (only those users are loaded), creates the rows in bulk, and
 * serialises concurrent callers (the command, the hub, a conversion) with a cache lock: volunteer_profiles has no unique(user_id)
 * and this change adds no migration. It also fills a missing department on an existing row when a RELIABLE source gives one.
 *
 * Department derivation chain (derive): the user's team profile -> the user's primary `user_departments` row -> the free-text
 * `users.department` matched against departments. The first two are written to the hub row; the free text is a guess and is only
 * ever used for DISPLAY, never persisted.
 */
class VolunteerHubSyncService
{
    public const LOCK_KEY = 'volunteers:sync-hub-profiles';

    /** The hub runs the sync at most once per this many seconds; the artisan command is never throttled. */
    public const HUB_THROTTLE_KEY = 'volunteers:hub-sync-throttle';
    public const HUB_THROTTLE_SECONDS = 60;

    /** Sources of a derived department that are reliable enough to be written to a profile. */
    private const WRITABLE_SOURCES = ['team_profile', 'primary_membership'];

    /**
     * The sync the hub runs: skipped (null) when it already ran within the last HUB_THROTTLE_SECONDS.
     *
     * @return array<string, mixed>|null
     */
    public function ensureProfilesThrottled(): ?array
    {
        try {
            if (! Cache::add(self::HUB_THROTTLE_KEY, 1, self::HUB_THROTTLE_SECONDS)) {
                return null;
            }
        } catch (Throwable $e) {
            Log::warning('VolunteerHubSyncService: throttle cache unavailable', ['error' => $e->getMessage()]);
        }

        try {
            $result = $this->ensureProfiles();
        } catch (Throwable $e) {
            Cache::forget(self::HUB_THROTTLE_KEY);
            throw $e;
        }

        if ($result['skipped_locked']) {
            Cache::forget(self::HUB_THROTTLE_KEY); // nothing was done: let the next hub call try again
        }

        return $result;
    }

    /**
     * @return array{created: int, from_role: int, from_team_profile: int, from_converted_request: int, departments_backfilled: int, skipped_locked: bool, dry_run: bool, derived: array<int, Department>, examined: list<int>}
     */
    public function ensureProfiles(bool $dryRun = false): array
    {
        $empty = [
            'created' => 0, 'from_role' => 0, 'from_team_profile' => 0, 'from_converted_request' => 0,
            'departments_backfilled' => 0, 'skipped_locked' => false, 'dry_run' => $dryRun,
            'derived' => [], 'examined' => [],
        ];

        if ($dryRun) {
            return $this->run($empty, true);
        }

        $lock = null;
        try {
            $lock = Cache::lock(self::LOCK_KEY, 60);
            if (! $lock->get()) {
                // Another request/command/conversion is working right now; the hub shows what exists and the next call catches up.
                return ['skipped_locked' => true] + $empty;
            }
        } catch (Throwable $e) {
            // No lock support in the cache store: carry on unlocked rather than break the hub.
            Log::warning('VolunteerHubSyncService: cache lock unavailable', ['error' => $e->getMessage()]);
            $lock = null;
        }

        try {
            return $this->run($empty, false);
        } finally {
            try {
                $lock?->release();
            } catch (Throwable) {
                // lock already expired / released
            }
        }
    }

    /**
     * Department for each user, by the fallback chain above (any source: for display). Users with none are absent.
     *
     * @param  list<int>  $userIds
     * @return array<int, Department> user id => department (id, name, name_ar)
     */
    public function derivedDepartments(array $userIds): array
    {
        return array_map(fn (array $hit) => $hit[0], $this->derive($userIds));
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array{0: Department, 1: string}> user id => [department, source]; source is team_profile | primary_membership | free_text
     */
    public function derive(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return [];
        }

        $found = []; // user id => [department id, source]

        // 1. The team profile (a volunteer one first, an active one first).
        TeamProfile::query()
            ->whereIn('user_id', $userIds)
            ->whereNotNull('department_id')
            ->orderByRaw("CASE WHEN member_type = 'volunteer' THEN 0 ELSE 1 END")
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get(['user_id', 'department_id'])
            ->each(function ($row) use (&$found) {
                $found[(int) $row->user_id] ??= [(int) $row->department_id, 'team_profile'];
            });

        // 2. The primary membership (any membership if none is flagged primary).
        $remaining = array_values(array_diff($userIds, array_keys($found)));
        if ($remaining !== []) {
            DB::table('user_departments')
                ->whereIn('user_id', $remaining)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get(['user_id', 'department_id'])
                ->each(function ($row) use (&$found) {
                    $found[(int) $row->user_id] ??= [(int) $row->department_id, 'primary_membership'];
                });
        }

        $departments = Department::query()->get(['id', 'name', 'name_ar', 'slug'])->keyBy('id');

        // 3. users.department free text (an id, a slug, or an Arabic/English name). Display only.
        $remaining = array_values(array_diff($userIds, array_keys($found)));
        if ($remaining !== [] && $departments->isNotEmpty()) {
            $lookup = [];
            foreach ($departments as $d) {
                foreach ([$d->slug, $d->name_ar, $d->name] as $key) {
                    if ($key !== null && trim((string) $key) !== '') {
                        $lookup[mb_strtolower(trim((string) $key))] ??= $d->id;
                    }
                }
            }

            User::query()->whereIn('id', $remaining)->whereNotNull('department')->get(['id', 'department'])
                ->each(function ($user) use (&$found, $lookup, $departments) {
                    $text = trim((string) $user->department);
                    if ($text === '') {
                        return;
                    }
                    $id = is_numeric($text) && $departments->has((int) $text) ? (int) $text : ($lookup[mb_strtolower($text)] ?? null);
                    if ($id !== null) {
                        $found[(int) $user->id] = [$id, 'free_text'];
                    }
                });
        }

        $result = [];
        foreach ($found as $userId => [$deptId, $source]) {
            if ($departments->has($deptId)) {
                $result[$userId] = [$departments[$deptId], $source];
            }
        }

        return $result;
    }

    /** Active volunteer team profiles that have no account: they cannot hold points, so the hub warns about them. */
    public function withoutAccountCount(): int
    {
        return TeamProfile::query()
            ->where('member_type', 'volunteer')
            ->where('is_active', true)
            ->whereNull('user_id')
            ->count();
    }

    // ─── internals ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $counts */
    private function run(array $counts, bool $dryRun): array
    {
        // Volunteers that have no hub profile, decided in SQL; only these users are loaded.
        $missingUsers = User::query()
            ->whereNotIn('id', VolunteerProfile::query()->select('user_id'))
            ->where(function ($q) {
                $q->where('role', 'volunteer')
                    ->orWhereIn('id', TeamProfile::query()->where('member_type', 'volunteer')->whereNotNull('user_id')->select('user_id'))
                    ->orWhereIn('id', TeamProfile::query()
                        ->whereNotNull('user_id')
                        ->whereIn('id', VolunteerRequest::query()->whereNotNull('converted_member_id')->select('converted_member_id'))
                        ->select('user_id'));
            })
            ->get(['id', 'role', 'is_active']);

        $missingIds = $missingUsers->pluck('id')->map(fn ($id) => (int) $id)->all();

        // For those users only: is their volunteer team profile active?   [user_id => any active]
        $teamActive = [];
        if ($missingIds !== []) {
            TeamProfile::query()
                ->where('member_type', 'volunteer')
                ->whereIn('user_id', $missingIds)
                ->get(['user_id', 'is_active'])
                ->each(function ($row) use (&$teamActive) {
                    $teamActive[(int) $row->user_id] = ($teamActive[(int) $row->user_id] ?? false) || (bool) $row->is_active;
                });
        }

        $counts['created'] = $missingUsers->count();
        foreach ($missingUsers as $user) {
            $bucket = $user->role === 'volunteer' ? 'from_role' : (isset($teamActive[(int) $user->id]) ? 'from_team_profile' : 'from_converted_request');
            $counts[$bucket]++;
        }

        // Existing rows with no department.
        $blankUserIds = VolunteerProfile::query()->whereNull('department_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $examined = array_values(array_unique(array_merge($missingIds, $blankUserIds)));
        $derived  = $this->derive($examined);

        $counts['derived']  = array_map(fn (array $hit) => $hit[0], $derived);
        $counts['examined'] = $examined;

        // Only a reliable source is written to a row; the free-text guess stays display-only.
        $writable = array_filter($derived, fn (array $hit) => in_array($hit[1], self::WRITABLE_SOURCES, true));

        $backfill = [];
        foreach ($blankUserIds as $userId) {
            if (isset($writable[$userId])) {
                $backfill[$writable[$userId][0]->id][] = $userId;
            }
        }
        $counts['departments_backfilled'] = array_sum(array_map('count', $backfill));

        if ($dryRun) {
            return $counts;
        }

        $now = now();
        $rows = [];
        foreach ($missingUsers as $user) {
            $id = (int) $user->id;
            $inactive = (isset($teamActive[$id]) && $teamActive[$id] === false) || $user->is_active === false;
            $rows[] = [
                'user_id'           => $id,
                'department_id'     => isset($writable[$id]) ? $writable[$id][0]->id : null,
                'status'            => $inactive ? VolunteerProfile::STATUS_INACTIVE : VolunteerProfile::STATUS_ACTIVE,
                'onboarding_status' => 'pending',
                'hours_logged'      => 0,
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        DB::transaction(function () use ($rows, $backfill, $now) {
            foreach (array_chunk($rows, 200) as $chunk) {
                VolunteerProfile::query()->insert($chunk);
            }

            foreach ($backfill as $deptId => $userIds) {
                foreach (array_chunk($userIds, 500) as $chunk) {
                    VolunteerProfile::query()
                        ->whereIn('user_id', $chunk)
                        ->whereNull('department_id')
                        ->update(['department_id' => $deptId, 'updated_at' => $now]);
                }
            }
        });

        return $counts;
    }
}
