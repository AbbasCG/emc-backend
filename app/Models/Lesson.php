<?php

namespace App\Models;

use App\Support\CourseContentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    protected $fillable = [
        'module_id', 'title', 'description', 'content',
        'video_url', 'duration_minutes', 'sort_order', 'status',
    ];

    public function isVisibleToStudents(): bool
    {
        return $this->status === CourseContentStatus::ACTIVE;
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class, 'lesson_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(CourseMaterial::class, 'lesson_id')->orderBy('sort_order');
    }
}
