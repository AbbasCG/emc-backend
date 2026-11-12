<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VolunteerProfile extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ON_LEAVE = 'on_leave';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_ON_LEAVE,
        self::STATUS_WITHDRAWN,
    ];

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

    public function statusLogs(): HasMany
    {
        return $this->hasMany(VolunteerStatusLog::class)->latest();
    }
}
