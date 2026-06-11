<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Department;
use App\Models\Instructor;
use App\Models\CourseFeature;
use App\Models\User;

class Course extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'track_id',
        'instructor_id',
        'department_id',
        'created_by',
        'assigned_by',
        'title',
        'slug',
        'description',
        'short_description',
        'type',
        'price',
        'is_free',
        'program_type',
        'session_format',
        'location',
        'location_type',
        'meeting_link',
        'is_online',
        'start_date',
        'start_time',
        'end_date',
        'end_time',
        'capacity',
        'registration_open',
        'registration_status',
        'status',
        'notes',
        'duration',
        'training_hours',
        'target_audience',
        'language',
        'level',
        'study_days',
        'study_time',
        'certificate',
        'certificate_available',
        'certificate_type',
        'learning_outcomes',
        'requirements',
        'curriculum_topics',
        'keywords',
        'course_image',
        'course_thumbnail',
        'requires_placement_test',
        'placement_test_type',
    ];

    protected $casts = [
        'start_date'            => 'date:Y-m-d',
        'end_date'              => 'date:Y-m-d',
        'learning_outcomes'     => 'array',
        'requirements'          => 'array',
        'curriculum_topics'     => 'array',
        'keywords'              => 'array',
        'is_online'             => 'boolean',
        'is_free'               => 'boolean',
        'registration_open'     => 'boolean',
        'certificate_available'  => 'boolean',
        'requires_placement_test'=> 'boolean',
    ];

    public function instructor()
    {
        return $this->belongsTo(Instructor::class);
    }

    public function track()
    {
        return $this->belongsTo(Track::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function features()
    {
        return $this->hasMany(CourseFeature::class)->orderBy('sort_order');
    }

    public function workshops(): HasMany
    {
        return $this->hasMany(Workshop::class)->orderBy('sort_order');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(LmsSession::class, 'course_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'course_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(CourseMaterial::class, 'course_id')->orderBy('sort_order');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('sort_order');
    }

    public function courseSessions(): HasMany
    {
        return $this->hasMany(CourseSession::class)->orderBy('sort_order');
    }

    public function courseAssignments(): HasMany
    {
        return $this->hasMany(CourseAssignment::class)->orderBy('sort_order');
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    public function placementTestAttempts(): HasMany
    {
        return $this->hasMany(PlacementTestAttempt::class);
    }

    public function oralAssessmentSlots(): HasMany
    {
        return $this->hasMany(OralAssessmentSlot::class);
    }

    public function oralAssessmentBookings(): HasMany
    {
        return $this->hasMany(OralAssessmentBooking::class);
    }

    public function requiresPlacement(): bool
    {
        return (bool) $this->requires_placement_test;
    }
}
