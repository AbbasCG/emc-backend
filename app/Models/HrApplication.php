<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrApplication extends Model
{
    protected $fillable = [
        'applicant_name', 'applicant_email', 'applicant_phone',
        'position', 'type', 'status',
        'cover_letter', 'cv_path',
        'department_id', 'reviewed_by', 'reviewed_at', 'notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(HrInterview::class, 'application_id');
    }
}
