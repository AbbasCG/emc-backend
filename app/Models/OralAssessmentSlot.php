<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OralAssessmentSlot extends Model
{
    protected $fillable = [
        'course_id',
        'instructor_id',
        'starts_at',
        'ends_at',
        'is_booked',
        'booked_by',
        'booking_status',
        'meeting_link',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at'   => 'datetime',
            'is_booked' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    public function booking(): HasOne
    {
        return $this->hasOne(OralAssessmentBooking::class, 'slot_id');
    }

    public function isAvailable(): bool
    {
        return $this->booking_status === 'available' && ! $this->is_booked;
    }

    public function scopeAvailable($query)
    {
        return $query->where('booking_status', 'available')->where('is_booked', false);
    }

    public function scopeForCourse($query, int $courseId)
    {
        return $query->where('course_id', $courseId);
    }
}
