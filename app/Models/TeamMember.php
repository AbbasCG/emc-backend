<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMember extends Model
{
    use Auditable;
    protected $fillable = [
        'user_id', 'team_profile_id', 'department_id', 'section_id', 'unit_id', 'role_title', 'status', 'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The specific canonical member profile this row operationalizes.
     * Explicit link (Stage 1 of the identity correction) rather than
     * inferring one via TeamProfile::where('user_id', ...)->first(), which
     * is ambiguous whenever a user has more than one profile.
     */
    public function teamProfile(): BelongsTo
    {
        return $this->belongsTo(TeamProfile::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(DepartmentSection::class, 'section_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(DepartmentalUnit::class, 'unit_id');
    }
}
