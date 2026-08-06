<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassGroupSchedule extends Model
{
    protected $fillable = [
        'class_group_id',
        'day_of_week',
        'start_time',
        'end_time',
        'delivery_mode',
        'location',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    public function classGroup()
    {
        return $this->belongsTo(ClassGroup::class);
    }
}
