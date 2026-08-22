<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class LearningPath extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'instructor_id',
        'created_by',
        'updated_by',
        'title',
        'slug',
        'catalog_code',
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
        'currency',
        'finance_approval_status',
        'submitted_for_finance_at',
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
        'learning_outcomes'        => 'array',
        'requirements'             => 'array',
        'study_days'               => 'array',
        'submitted_for_finance_at' => 'datetime',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function financeApproval()
    {
        return $this->morphOne(ProgramFinanceApproval::class, 'approvable');
    }

    public function isPaid(): bool
    {
        return (float) $this->price > 0;
    }

    public function isFree(): bool
    {
        return ! $this->isPaid();
    }

    public function requiresFinanceApproval(): bool
    {
        return $this->isPaid();
    }

    /** Defense in depth: paid paths cannot leak into the public catalog before approval. */
    public function scopeFinanceApprovedForPublic($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('price')
                ->orWhere('price', '<=', 0)
                ->orWhere('finance_approval_status', 'approved');
        });
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
