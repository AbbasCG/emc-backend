<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepartmentSection extends Model
{
    protected $fillable = [
        'department_id', 'name', 'description', 'leader_id', 'status', 'sort_order',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'section_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'section_id');
    }
}
