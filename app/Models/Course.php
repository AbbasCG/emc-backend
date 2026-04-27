<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'description',
        'short_description',
        'instructor_name',
        'type',
        'price',
        'location',
        'is_online',
        'start_date',
        'end_date',
        'capacity',
        'status',
    ];
}
