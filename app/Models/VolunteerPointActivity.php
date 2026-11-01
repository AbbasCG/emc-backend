<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VolunteerPointActivity extends Model
{
    protected $fillable = [
        'category_key',
        'category_name_ar',
        'name_ar',
        'type',
        'points_min',
        'points_max',
        'description_ar',
        'requires_attachment',
        'monthly_cap',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'points_min' => 'integer',
        'points_max' => 'integer',
        'requires_attachment' => 'boolean',
        'monthly_cap' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
