<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LearningPath extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'instructor_id',
        'title',
        'slug',
        'short_description',
        'full_description',
        'featured_image',
        'duration',
        'duration_unit',
        'language',
        'level',
        'certificate_name',
        'price',
        'discount_price',
        'status',
        'is_featured',
        'enrollment_open',
        'learning_outcomes',
        'requirements',
        'study_days_per_week',
        'study_days',
        'study_time',
        'schedule_note',
    ];

    protected $casts = [
        'is_featured'      => 'boolean',
        'enrollment_open'  => 'boolean',
        'price'            => 'decimal:2',
        'discount_price'   => 'decimal:2',
        'learning_outcomes' => 'array',
        'requirements'     => 'array',
        'study_days'       => 'array',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'learning_path_items')
            ->withPivot('sort_order')
            ->orderBy('learning_path_items.sort_order');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LearningPathItem::class)->orderBy('sort_order');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(LearningPathEnrollment::class);
    }
}
