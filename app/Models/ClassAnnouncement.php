<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassAnnouncement extends Model
{
    use SoftDeletes;

    public const PRIORITIES = ['normal', 'important', 'urgent'];
    public const STATUSES = ['draft', 'published', 'archived'];

    protected $fillable = [
        'course_id',
        'class_group_id',
        'created_by',
        'title',
        'body',
        'priority',
        'status',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
