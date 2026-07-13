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
        'provider', 'is_default_for_provider',
        'opening_balance', 'current_balance',
        'notes', 'is_active', 'created_by', 'updated_by',
        'bank_name', 'account_holder', 'iban', 'account_number',
    ];

    protected $casts = [
        'opening_balance'          => 'decimal:2',
        'current_balance'          => 'decimal:2',
        'is_active'                => 'boolean',
        'is_default_for_provider'  => 'boolean',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceAccountTransaction::class);
    }

    /**
     * Resolve the active finance account for a given provider and currency.
     * Prefers the account marked is_default_for_provider; falls back to the first active match.
     */
    public static function forProvider(string $provider, string $currency): ?self
    {
        $currency = strtoupper($currency);
        return static::where('provider', $provider)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->orderByDesc('is_default_for_provider')
            ->first();
    }
}
