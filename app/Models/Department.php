<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'leader_id', 'parent_id',
        'status', 'icon', 'color', 'sort_order',
        // Bilingual + public-display columns (Phase RBAC / EMC V3 team)
        'name_ar', 'name_en', 'description_ar', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
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
