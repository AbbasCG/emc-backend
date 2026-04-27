<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Registration extends Model
{
    protected $fillable = [
        'course_id',
        'full_name',
        'phone',
        'email',
        'city',
        'gender',
        'notes',
        'status',

    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    
}
