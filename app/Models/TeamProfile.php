<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamProfile extends Model
{
    protected $fillable = [
        'department_id',
        'user_id',
        'name_ar',
        'name_en',
        'position_ar',
        'position_en',
        'role_key',
        'country',
        'image',
        'bio_ar',
        'sort_order',
        'is_leader',
        'is_executive',
        'is_active',
        // Volunteer-conversion fields
        'phone',
        'email',
        'city',
        'gender',
        'skills',
        'cv_file_path',
        'member_type',
        'source',
        'source_id',
        'is_new_member',
        'joined_at',
        'photo_publication_consent',
        'professional_profile_consent',
    ];

    protected $casts = [
        'is_leader'     => 'boolean',
        'is_executive'  => 'boolean',
        'is_active'     => 'boolean',
        'is_new_member' => 'boolean',
        'joined_at'     => 'datetime',
        'photo_publication_consent'    => 'boolean',
        'professional_profile_consent' => 'boolean',
    ];

    /**
     * Consent gating only applies to rows created via the volunteer HR flow
     * (source='volunteer_hr_profile') — directly-added team members (admin
     * CRUD) never went through a consent form and must keep displaying
     * exactly as before this feature existed.
     */
    public function requiresConsentGating(): bool
    {
        return $this->source === 'volunteer_hr_profile';
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    /** The operational department/unit placement for this canonical member, if any. */
    public function teamMember(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TeamMember::class);
    }

    public function hasUserAccount(): bool
    {
        return $this->user_id !== null;
    }

    /** Scope: only active members. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: ordered for public display.
     * Executives first → leaders second → then by sort_order.
     */
    public function scopePublicOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc('is_executive')
            ->orderByDesc('is_leader')
            ->orderBy('sort_order');
    }
}
