<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassGroupStudentHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'class_group_student_id',
        'user_id',
        'from_class_group_id',
        'to_class_group_id',
        'action',
        'reason',
        'changed_by',
        'changed_at',
    ];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function classGroupStudent(): BelongsTo
    {
        return $this->belongsTo(ClassGroupStudent::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
