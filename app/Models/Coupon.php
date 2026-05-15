<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'discount_type', 'discount_value',
        'max_uses', 'used_count', 'starts_at', 'expires_at', 'status',
        'applies_to_type', 'applies_to_id',
    ];

    protected $casts = [
        'starts_at'      => 'datetime',
        'expires_at'     => 'datetime',
        'discount_value' => 'decimal:2',
    ];

    public function appliesTo(): MorphTo
    {
        return $this->morphTo('applies_to');
    }

    public function isValid(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }
        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }
        if ($this->expires_at && $now->gt($this->expires_at)) {
            return false;
        }

        return true;
    }

    public function calculateDiscount(float $price): float
    {
        if ($this->discount_type === 'percentage') {
            return round($price * ($this->discount_value / 100), 2);
        }

        return min((float) $this->discount_value, $price);
    }
}
