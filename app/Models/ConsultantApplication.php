<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultantApplication extends Model
{
    public const STATUSES = ['new', 'under_review', 'interview', 'accepted', 'rejected', 'archived'];

    protected $fillable = [
        'full_name', 'email', 'phone', 'country', 'city',
        'specialty', 'desired_department', 'years_experience', 'linkedin_url', 'cv_file_path',
        'motivation', 'availability',
        'status', 'reviewer_note', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'years_experience' => 'integer',
        'reviewed_at'      => 'datetime',
    ];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
