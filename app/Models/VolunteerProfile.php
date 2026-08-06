<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VolunteerProfile extends Model
{
    protected $fillable = [
        'user_id', 'department_id', 'section_id', 'status', 'skills',
        'availability', 'hours_logged', 'onboarding_status', 'notes',
    ];

    protected $casts = [
        'skills' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(DepartmentSection::class, 'section_id');
    }
}
