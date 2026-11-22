<?php

namespace App\Services;

use App\Exceptions\VolunteerConversionException;
use App\Mail\VolunteerAcceptedMail;
use App\Models\Department;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerProfile;
use App\Models\VolunteerRequest;
use App\Models\VolunteerStatusLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * The ONE way an accepted volunteer request becomes a member ("Path A").
 *
 * In a single database transaction it
 *   1. creates (or re-uses, matched on e-mail) the user account: new accounts get role `department_member`, a random temporary
 *      password and must_change_password = true; an existing `student` / `volunteer` account is upgraded to `department_member`;
 *      any other role keeps its role and is only linked. An existing account whose e-mail was NEVER VERIFIED is not trusted (anybody
 *      could have pre-registered that address with a password they know): its password is replaced by a temporary one, its tokens
 *      are revoked and the person is mailed the new temporary password. A suspended account is refused;
 *   2. attaches the account to a department (primary only if it has no primary yet) and seats it, unassigned, in the team;
 *   3. creates the team_profiles row (what the Members page, Team management and the public /team page read);
 *   4. creates / reconciles the volunteer_profiles row (what the Operations Volunteer Hub and Impact Points read);
 *   5. stamps the request as converted.
 * Everything that is NOT data (the e-mail, the notification, the audit entry) happens after commit and can never undo it.
 *
 * ONE placement rule: an account that already has a team profile / seat keeps that department everywhere (profile, seat, hub profile,
 * response); the department HR chose is used only when the account has no placement yet.
 *
 * The temporary password exists only in memory for the length of the request: it is hashed into users.password and put in the e-mail
 * body, nowhere else (not in the response, not in logs, not in a queue payload).
 */
class VolunteerConversionService
{
    public const ROLE = 'department_member';

    /** Roles a self-registered / learner account can have: the only ones whose unverified e-mail is re-credentialled, and re-issuable. */
    private const LEARNER_ROLES = ['student', 'volunteer'];

    /**
     * @return array{member: TeamProfile, account: User, department: Department, requested_department: Department, account_created: bool, account_existing: bool, credentials_reissued: bool, temporary_password: ?string}
     *
     * @throws VolunteerConversionException when the request may not be converted (already converted, not accepted, unusable account)
     */
    public function convert(VolunteerRequest $volunteerRequest, Department $department, User $actor): array
    {
        return DB::transaction(function () use ($volunteerRequest, $department, $actor) {
            // Serialise two HR users clicking at once: the second waits here, then sees the request already converted.
            $request = VolunteerRequest::query()->whereKey($volunteerRequest->id)->lockForUpdate()->firstOrFail();

            if ($request->isConverted()) {
                throw new VolunteerConversionException(VolunteerConversionException::ALREADY_CONVERTED, 'تم تحويل هذا الطلب مسبقاً إلى عضو.', 409);
            }
            if ($request->status !== 'accepted') {
                throw new VolunteerConversionException(
                    VolunteerConversionException::NOT_ACCEPTED,
                    'لا يمكن تحويل الطلب إلا إذا كان مقبولاً. الحالة الحالية: ' . $request->status,
                );
            }

            $email = mb_strtolower(trim((string) $request->email));
            if ($email === '') {
                throw new VolunteerConversionException(VolunteerConversionException::NO_EMAIL, 'لا يوجد بريد إلكتروني في طلب التطوع، لا يمكن إنشاء حساب.');
            }

            $existing = $this->findByEmail($email);
            if ($existing !== null) {
                $this->assertUsable($existing);
            }

            // The department the member really lands in (the existing placement wins over the one HR picked).
            $placed = $this->placementDepartment($existing, $department);

            $created = false;
            $reissued = false;
            $temporaryPassword = null;

            if ($existing === null) {
                [$account, $temporaryPassword] = $this->createAccount($request, $email, $placed);
                if ($account === null) {
                    // Lost a race with a request that created this e-mail a moment ago: link to that account instead.
                    $existing = $this->findByEmail($email);
                    if ($existing === null) {
                        throw new VolunteerConversionException(VolunteerConversionException::NO_EMAIL, 'تعذّر إنشاء الحساب، حاول مرة أخرى.', 409);
                    }
                    $this->assertUsable($existing);
                    $placed = $this->placementDepartment($existing, $department);
                } else {
                    $created = true;
                }
            }

            if (! $created) {
                $account = $existing;
                [$temporaryPassword, $reissued] = $this->linkExisting($account, $placed);
            }

            $member = $this->resolveTeamProfile($request, $placed, $account);

            $this->attachDepartment($account, $placed);

            $this->ensureSeat($member, $account, $placed);

            $this->syncVolunteerProfile($account, $placed, $actor);

            $request->update([
                'status'                 => 'converted_to_member',
                'converted_to_member_at' => now(),
                'converted_member_id'    => $member->id,
                'converted_by'           => $actor->id,
            ]);

            return [
                'member'               => $member,
                'account'              => $account,
                'department'           => $placed,
                'requested_department' => $department,
                'account_created'      => $created,
                'account_existing'     => ! $created,
                'credentials_reissued' => $reissued,
                'temporary_password'   => $temporaryPassword,
            ];
        }, 3);
    }

    /**
     * Re-issues the temporary password of a converted volunteer who has not changed it yet.
     * Only for accounts that are department members (what a conversion makes of a new / learner account); any other role is refused.
     *
     * @return array{account: User, department_name: string, temporary_password: string}
     *
     * @throws VolunteerConversionException when there is nothing to re-issue
     */
    public function reissueCredentials(VolunteerRequest $volunteerRequest): array
    {
        if (! $volunteerRequest->isConverted()) {
            throw new VolunteerConversionException(VolunteerConversionException::NOT_ACCEPTED, 'هذا الطلب لم يتم تحويله إلى عضو بعد.');
        }

        $member = TeamProfile::query()->with('department:id,name,name_ar')->find($volunteerRequest->converted_member_id);
        if (! $member?->user_id) {
            throw new VolunteerConversionException(VolunteerConversionException::NOT_ACCEPTED, 'لا يوجد حساب مرتبط بهذا العضو.');
        }

        $temporaryPassword = $this->newTemporaryPassword();

        $account = DB::transaction(function () use ($member, $temporaryPassword) {
            $account = User::query()->whereKey($member->user_id)->lockForUpdate()->first();

            if (! $account) {
                throw new VolunteerConversionException(VolunteerConversionException::NOT_ACCEPTED, 'لا يوجد حساب مرتبط بهذا العضو.');
            }
            if ($account->role !== self::ROLE) {
                throw new VolunteerConversionException(
                    VolunteerConversionException::ROLE_NOT_ELIGIBLE,
                    'لا يمكن إعادة إرسال بيانات الدخول لهذا الحساب لأن دوره ليس عضو إدارة.'
                );
            }
            if (! $account->is_active) {
                throw new VolunteerConversionException(VolunteerConversionException::ACCOUNT_INACTIVE, 'هذا الحساب موقوف.');
            }
            if (! $account->must_change_password) {
                throw new VolunteerConversionException(
                    VolunteerConversionException::NOT_ACCEPTED,
                    'لا يمكن إعادة إرسال بيانات الدخول: صاحب الحساب غيّر كلمة المرور المؤقتة بالفعل.'
                );
            }

            $account->forceFill(['password' => Hash::make($temporaryPassword), 'must_change_password' => true])->save();
            // Whatever was signed in with the previous temporary password is no longer valid.
            $account->tokens()->delete();

            return $account;
        });

        return [
            'account'            => $account,
            'department_name'    => (string) ($member->department?->name_ar ?? $member->department?->name ?? ''),
            'temporary_password' => $temporaryPassword,
        ];
    }

    /**
     * Sends the "you were accepted" e-mail. Never throws: a mail failure must not undo or fail the conversion.
     * Synchronous on purpose (a queued mailable would persist the plain password in the queue payload).
     *
     * When the default mailer only writes mail to a log (or an in-memory array) outside the testing environment, nothing was delivered,
     * and writing the message would put the temporary password into a log file: that counts as NOT sent and nothing is written.
     *
     * @return bool true when the mail was handed to a real mailer
     */
    public function sendAcceptedMail(User $account, string $departmentName, ?string $temporaryPassword): bool
    {
        $mail = new VolunteerAcceptedMail($account->name, $account->email, $departmentName, $temporaryPassword);
        // Email Logs keeps the attempt; the payload is the department only, never the password.
        $payload = ['department' => $departmentName];

        if (! $this->mailerCanDeliver()) {
            Log::warning('VolunteerAcceptedMail not sent: the default mailer does not deliver (log/array).', ['user_id' => $account->id]);
            EmailService::record('volunteer_accepted', $account->email, $account->name, $account->id, EmailService::subjectOf($mail),
                'failed', $payload, [], 'لم يُرسل: مزوّد البريد الافتراضي لا يرسل فعليًا (log/array).');

            return false;
        }

        return EmailService::sendNow('volunteer_accepted', $account->email, $account->name, $account->id, $mail, $payload);
    }

    public function mailerCanDeliver(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        return ! in_array((string) config('mail.default'), ['log', 'array'], true);
    }

    public function newTemporaryPassword(): string
    {
        return Str::password(12, letters: true, numbers: true, symbols: false, spaces: false);
    }

    // --- Steps -----------------------------------------------------------------------------------------------------------------

    /** The e-mail column is case-insensitive in the database, so a plain equality finds "Ahmed@X.com" for "ahmed@x.com" and uses the index. */
    private function findByEmail(string $lowerEmail): ?User
    {
        return User::withTrashed()->where('email', $lowerEmail)->first();
    }

    private function assertUsable(User $account): void
    {
        if ($account->trashed()) {
            throw new VolunteerConversionException(
                VolunteerConversionException::ACCOUNT_DELETED,
                'يوجد حساب محذوف بنفس البريد الإلكتروني. يجب استعادته أو تغيير البريد قبل التحويل.'
            );
        }
        if (! $account->is_active) {
            throw new VolunteerConversionException(
                VolunteerConversionException::ACCOUNT_INACTIVE,
                'الحساب المرتبط بهذا البريد الإلكتروني موقوف. فعّل الحساب أولاً ثم أعد التحويل.'
            );
        }
    }

    /** The department the account is ALREADY placed in (team profile, then seat), else the one HR chose. */
    private function placementDepartment(?User $account, Department $chosen): Department
    {
        if ($account === null) {
            return $chosen;
        }

        $deptId = TeamProfile::query()->where('user_id', $account->id)->whereNotNull('department_id')->orderBy('id')->value('department_id')
            ?? TeamMember::query()->where('user_id', $account->id)->whereNotNull('department_id')->orderBy('id')->value('department_id');

        if ($deptId === null || (int) $deptId === $chosen->id) {
            return $chosen;
        }

        return Department::query()->find($deptId) ?? $chosen;
    }

    /** @return array{0: ?User, 1: ?string} [account, temporaryPassword]; account is null when the e-mail was taken a moment ago */
    private function createAccount(VolunteerRequest $request, string $email, Department $placed): array
    {
        $temporaryPassword = $this->newTemporaryPassword();

        try {
            $account = User::create([
                'name'                 => $request->full_name,
                'email'                => $email,
                'password'             => Hash::make($temporaryPassword),
                'must_change_password' => true,
                'role'                 => self::ROLE,
                'phone'                => $request->phone,
                'country'              => $request->country,
                'city'                 => $request->city,
                'gender'               => $request->gender,
                'department'           => $placed->name_ar ?? $placed->name,
                'is_active'            => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            return [null, null];
        }

        return [$account, $temporaryPassword];
    }

    /** @return array{0: ?string, 1: bool} [temporaryPassword, reissued] */
    private function linkExisting(User $account, Department $placed): array
    {
        $temporaryPassword = null;
        $reissued = false;

        // Nobody proved they own this address: whoever pre-registered it may know the password. Replace it, drop every session, and
        // mail the address owner a temporary one. (Only learner-type accounts: an admin-made account is never touched this way.)
        if ($account->email_verified_at === null && in_array($account->role, [...self::LEARNER_ROLES, self::ROLE], true)) {
            $temporaryPassword = $this->newTemporaryPassword();
            $account->forceFill(['password' => Hash::make($temporaryPassword), 'must_change_password' => true])->save();
            $account->tokens()->delete();
            $reissued = true;
        }

        // Only a learner / plain volunteer account is promoted. Anyone else (instructor, admin, partner, ...) keeps their role.
        if (in_array($account->role, self::LEARNER_ROLES, true)) {
            $account->update(['role' => self::ROLE, 'department' => $placed->name_ar ?? $placed->name]);
        }

        return [$temporaryPassword, $reissued];
    }

    /**
     * team_profiles.user_id is unique: an account that already has a team profile keeps it (reactivated, because HR just accepted the
     * person; it only gets a department when it had none); otherwise a new one is created from the request.
     */
    private function resolveTeamProfile(VolunteerRequest $request, Department $placed, User $account): TeamProfile
    {
        $existing = TeamProfile::query()->where('user_id', $account->id)->lockForUpdate()->first();
        if ($existing) {
            $changes = [];
            if ($existing->department_id === null) {
                $changes['department_id'] = $placed->id;
            }
            if (! $existing->is_active) {
                $changes['is_active'] = true;
            }
            if ($changes !== []) {
                $existing->update($changes);
            }

            return $existing;
        }

        $maxSort = TeamProfile::query()->where('department_id', $placed->id)->max('sort_order') ?? 0;

        return TeamProfile::create([
            'department_id' => $placed->id,
            'user_id'       => $account->id,
            'name_ar'       => $request->full_name,
            'position_ar'   => 'عضو إدارة',
            'role_key'      => self::ROLE,
            'phone'         => $request->phone,
            'email'         => $request->email,
            'country'       => $request->country,
            'city'          => $request->city,
            'gender'        => $request->gender,
            'skills'        => $request->skills,
            'cv_file_path'  => $request->cv_file_path,
            'sort_order'    => $maxSort + 1,
            'is_leader'     => false,
            'is_executive'  => false,
            'is_active'     => true,
            'is_new_member' => true,
            'member_type'   => 'volunteer',
            'source'        => 'volunteer_request',
            'source_id'     => $request->id,
            'joined_at'     => now(),
        ]);
    }

    /** Membership pivot: never a second primary, never touches an existing row. */
    private function attachDepartment(User $account, Department $placed): void
    {
        $pivot = DB::table('user_departments')->where('user_id', $account->id);

        if ((clone $pivot)->where('department_id', $placed->id)->exists()) {
            return;
        }

        $account->departments()->attach($placed->id, ['is_primary' => ! (clone $pivot)->where('is_primary', true)->exists()]);
    }

    /** The unassigned seat; an existing seat is kept as it is (reactivated together with its reactivated profile). */
    private function ensureSeat(TeamProfile $member, User $account, Department $placed): void
    {
        $seat = TeamMember::query()->firstOrCreate(
            ['team_profile_id' => $member->id],
            [
                'user_id'       => $account->id,
                'department_id' => $member->department_id ?? $placed->id,
                'section_id'    => null, // unassigned, waiting for the department manager to seat them
                'role_title'    => 'عضو إدارة',
                'status'        => 'active',
                'joined_at'     => now(),
            ]
        );

        if (! $seat->wasRecentlyCreated && $seat->status !== 'active') {
            $seat->update(['status' => 'active']);
        }
    }

    /**
     * The row the Operations Volunteer Hub and Impact Points are built on. volunteer_profiles has no unique(user_id), so look first, and
     * create under the same cache lock the hub sync uses (held until this transaction commits) so the two cannot both insert.
     *
     * An EXISTING row keeps what it has: only a missing department is filled, and only `inactive` becomes `active` (with a status log).
     * withdrawn / on_leave stay exactly as they are.
     */
    private function syncVolunteerProfile(User $account, Department $placed, User $actor): void
    {
        $profile = $this->findHubProfile($account);

        if ($profile === null) {
            $this->holdHubLockUntilCommit();
            $profile = $this->findHubProfile($account); // another request may have created it while we waited
        }

        if ($profile === null) {
            VolunteerProfile::create([
                'user_id'           => $account->id,
                'department_id'     => $placed->id,
                'status'            => VolunteerProfile::STATUS_ACTIVE,
                'onboarding_status' => 'pending',
            ]);

            return;
        }

        $changes = [];
        if ($profile->department_id === null) {
            $changes['department_id'] = $placed->id;
        }
        $reactivated = $profile->status === VolunteerProfile::STATUS_INACTIVE;
        if ($reactivated) {
            $changes['status'] = VolunteerProfile::STATUS_ACTIVE;
        }
        if ($changes !== []) {
            $profile->update($changes);
        }

        if ($reactivated) {
            VolunteerStatusLog::create([
                'volunteer_profile_id' => $profile->id,
                'user_id'              => $account->id,
                'old_status'           => VolunteerProfile::STATUS_INACTIVE,
                'new_status'           => VolunteerProfile::STATUS_ACTIVE,
                'reason'               => 'إعادة تفعيل تلقائية عند قبول طلب التطوع وتحويله إلى عضوية.',
                'changed_by'           => $actor->id,
            ]);
        }
    }

    private function findHubProfile(User $account): ?VolunteerProfile
    {
        return VolunteerProfile::query()->where('user_id', $account->id)->orderBy('id')->lockForUpdate()->first();
    }

    private function holdHubLockUntilCommit(): void
    {
        try {
            $lock = Cache::lock(VolunteerHubSyncService::LOCK_KEY, 20);
            $lock->block(5);
            // Released once the surrounding transaction has committed (on a rollback it simply expires).
            DB::afterCommit(function () use ($lock) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // already expired
                }
            });
        } catch (Throwable $e) {
            // The sync was busy for 5 s, or the cache has no locks: carry on, the re-check below still protects the common case.
            Log::notice('VolunteerConversionService: hub lock not taken', ['error' => $e->getMessage()]);
        }
    }
}
