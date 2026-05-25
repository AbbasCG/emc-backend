<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseModule extends Model
{
    use SoftDeletes;
    protected $table = 'course_modules';

    protected $fillable = ['course_id', 'title', 'description', 'sort_order', 'status', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'module_id')->orderBy('sort_order');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CourseSession::class, 'module_id')->orderBy('sort_order');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(CourseMaterial::class, 'module_id')->orderBy('sort_order');
    }

    public function courseAssignments(): HasMany
    {
        return $this->hasMany(CourseAssignment::class, 'module_id')->orderBy('sort_order');
    }
}
