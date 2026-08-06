<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLock extends Model
{
    public $timestamps = false;

    protected $fillable = ['session_id', 'course_session_id', 'locked_by', 'locked_at'];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime'];
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
