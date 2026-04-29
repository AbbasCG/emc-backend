<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Instructor;
use App\Models\CourseFeature;

class Course extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'description',
        'short_description',
        'instructor_name',
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
        'instructor_title',
        'instructor_bio',
        'instructor_image',
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

    public function features()
    {
        return $this->hasMany(CourseFeature::class)->orderBy('sort_order');
    }
}
