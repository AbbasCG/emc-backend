<?php

namespace App\Models;

use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'role',
        'phone', 'phone_country_code', 'is_active', 'department', 'avatar',
        'city', 'country', 'country_code', 'gender',
        'how_did_you_hear_about_us', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** Full public URL for the avatar, or null if not set. */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->avatar)
            : null;
    }

    /**
     * Per-instance permission cache — avoids repeat DB queries within one request.
     */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'is_active'         => 'boolean',
        ];
    }

    // ─── Role Helpers ────────────────────────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin', 'executive_admin'], true);
    }

    public function isTeacher(): bool
    {
        return in_array($this->role, ['teacher', 'instructor'], true);
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    public function isPartner(): bool
    {
        return $this->role === 'partner';
    }

    public function isVolunteer(): bool
    {
        return $this->role === 'volunteer';
    }

    /**
     * Check if the user has one of the given role names.
     *
     * Supports both the legacy 'teacher' slug and the new 'instructor' slug.
     * When 'instructor' is in $roles, 'teacher' is implicitly accepted (backward compat).
     */
    public function hasRole(string ...$roles): bool
    {
        // Normalize: treat instructor <=> teacher as synonyms
        $effective = $this->effectiveRole();

        foreach ($roles as $role) {
            if ($role === $effective || $role === $this->role) {
                return true;
            }
            // instructor requested → also accept teacher (and vice versa)
            if (($role === 'instructor' && $this->role === 'teacher') ||
                ($role === 'teacher' && $this->role === 'instructor')) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->hasRole(...$roles);
    }

    // ─── Permission Helpers ──────────────────────────────────────────────────

    /**
     * Return all permission names granted to this user's role.
     *
     * Resolution order:
     * 1. DB role-permission mapping (authoritative after seeding)
     * 2. Static Permissions::forRole() fallback (before DB is seeded)
     */
    public function allPermissions(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        // super_admin gets everything without touching the DB
        if ($this->isSuperAdmin()) {
            return $this->permissionCache = Permissions::all();
        }

        $dbRole = Role::with('permissions')
            ->where('name', $this->effectiveRole())
            ->first();

        $this->permissionCache = $dbRole
            ? $dbRole->permissions->pluck('name')->toArray()
            : Permissions::forRole($this->role);

        return $this->permissionCache;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->allPermissions(), true);
    }

    /** Flush per-instance cache (e.g., after role changes). */
    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function instructorProfile(): HasOne
    {
        return $this->hasOne(Instructor::class, 'user_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function assignmentSubmissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function studentProgress(): HasMany
    {
        return $this->hasMany(StudentProgress::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function volunteerProfile(): HasOne
    {
        return $this->hasOne(VolunteerProfile::class);
    }

    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function organizedMeetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'organizer_id');
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function scholarships(): HasMany
    {
        return $this->hasMany(Scholarship::class);
    }

    // ─── Internal Helpers ────────────────────────────────────────────────────

    /**
     * Map legacy role slugs to their canonical form for DB lookups.
     * 'teacher' → 'instructor' (the canonical DB role name).
     */
    private function effectiveRole(): string
    {
        return match ($this->role) {
            'teacher' => 'instructor',
            default   => $this->role,
        };
    }
}
