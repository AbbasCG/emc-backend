<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Track extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'outcome_title',
        'duration',
        'level',
        'status',
        'image',
        'price',
        'is_free',
    ];

    protected $casts = [
        'is_free' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function studentProgress(): HasMany
    {
        return $this->hasMany(StudentProgress::class, 'track_id');
    }
}
