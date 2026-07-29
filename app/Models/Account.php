<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'type',
        'parent_id',
        'is_selectable',
    ];

    protected $casts = [
        'is_selectable' => 'boolean',
    ];

    /**
     * Parent account.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    /**
     * Direct children accounts.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id')->orderBy('code', 'asc');
    }

    /**
     * Recursive children accounts.
     */
    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    /**
     * Scope for root level accounts (parent_id IS NULL).
     */
    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id')->orderBy('code', 'asc');
    }

    /**
     * Scope for selectable accounts only.
     */
    public function scopeSelectable($query)
    {
        return $query->where('is_selectable', true);
    }
}
