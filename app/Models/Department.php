<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Department extends Model
{
    use Auditable;
    protected $fillable = [
        'name', 'slug', 'description', 'leader_id', 'deputy_leader_id', 'operational_member_id',
        'parent_id', 'status', 'icon', 'color', 'sort_order',
        // Bilingual + public-display columns (Phase RBAC / EMC V3 team)
        'name_ar', 'name_en', 'description_ar', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * Canonical "currently part of active organizational management" filter —
     * the `status` column ('active'/'inactive'/'archived') is the single
     * source of truth for this, not a second ad-hoc flag. A department a
     * duplicate-merge command archived (see
     * MergeDuplicateAiDepartmentCommand) must disappear from management
     * listings and new-assignment selectors via this scope, while remaining
     * fully resolvable by ID for historical reports/records — this scope is
     * deliberately never applied to single-row lookups or relation resolution.
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'active');
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function deputyLeader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deputy_leader_id');
    }

    public function operationalMember(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operational_member_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(DepartmentalUnit::class, 'department_id');
    }

    public function userDepartments(): HasMany
    {
        return $this->hasMany(UserDepartment::class, 'department_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(DepartmentSection::class)->orderBy('sort_order');
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_departments')
                    ->withPivot('is_primary')
                    ->withTimestamps();
    }

    /** Public website team profiles (no user account required). */
    public function teamProfiles(): HasMany
    {
        return $this->hasMany(TeamProfile::class)->orderBy('sort_order');
    }

    public function activeTeamProfiles(): HasMany
    {
        return $this->hasMany(TeamProfile::class)
            ->where('is_active', true)
            ->orderByDesc('is_executive')
            ->orderByDesc('is_leader')
            ->orderBy('sort_order');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function volunteers(): HasMany
    {
        return $this->hasMany(VolunteerProfile::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }
}
