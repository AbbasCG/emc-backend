<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'type', 'currency',
        'opening_balance', 'current_balance',
        'notes', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_active'       => 'boolean',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceAccountTransaction::class);
    }
}
