<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Instructor;
use App\Models\CourseFeature;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'track_id',
        'instructor_id',
        'title',
        'slug',
        'description',
        'short_description',
        'type',
        'price',
        'location',
        'is_online',
        'start_date',
        'end_date',
        'capacity',
        'status',
        'duration',
        'training_hours',
        'target_audience',
        'language',
        'level',
        'study_days',
        'study_time',
        'certificate',
        'learning_outcomes',
        'course_image',
    ];

    protected $casts = [
        'learning_outcomes' => 'array',
        'is_online' => 'boolean',
    ];

    public function instructor()
    {
        return $this->belongsTo(Instructor::class);
    }

    public function track()
    {
        return $this->belongsTo(Track::class);
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

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }
}
