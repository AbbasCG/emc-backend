<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_id',
        'request_type',
        'required_count',
        'skills_and_specs',
        'notes',
        'status',
        'target_audience',
        'hr_feedback',
        'created_by',
    ];

    protected $casts = [
        'required_count' => 'integer',
    ];

    /**
     * Department requesting staff.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * User who created the request.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
