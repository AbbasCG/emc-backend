<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseMaterial extends Model
{
    protected $fillable = [
        'course_id',
        'workshop_id',
        'session_id',
        'title',
        'description',
        'file_path',
        'external_url',
        'type',
        'sort_order',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class, 'workshop_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LmsSession::class, 'session_id');
    }
}
