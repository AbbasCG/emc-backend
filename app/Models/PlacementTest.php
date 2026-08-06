<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlacementTest extends Model
{
    use Auditable;
    protected $fillable = [
        'title',
        'description',
        'language',
        'duration_minutes',
        'total_questions',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active'        => 'boolean',
            'duration_minutes' => 'integer',
            'total_questions'  => 'integer',
        ];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(PlacementTestQuestion::class)->orderBy('order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PlacementTestAttempt::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
