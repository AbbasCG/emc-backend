<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamProfile extends Model
{
    protected $fillable = [
        'department_id',
        'name_ar',
        'name_en',
        'position_ar',
        'position_en',
        'role_key',
        'country',
        'image',
        'bio_ar',
        'sort_order',
        'is_leader',
        'is_executive',
        'is_active',
    ];

    protected $casts = [
        'is_leader'    => 'boolean',
        'is_executive' => 'boolean',
        'is_active'    => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Scope: only active members. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: ordered for public display.
     * Executives first → leaders second → then by sort_order.
     */
    public function scopePublicOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc('is_executive')
            ->orderByDesc('is_leader')
            ->orderBy('sort_order');
    }
}
