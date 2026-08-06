<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'course_id',
        'order_number',
        'type',
        'subtotal',
        'discount_amount',
        'coupon_id',
        'coupon_code_snapshot',
        'discount_type_snapshot',
        'discount_value_snapshot',
        'tax_amount',
        'total',
        'currency',
        'status',
        'payment_provider',
        'provider_session_id',
        'provider_payment_intent_id',
        'paid_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'paid_at'  => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_value_snapshot' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total'    => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public static function generateOrderNumber(): string
    {
        return 'ORD-' . strtoupper(substr(uniqid('', true), -8)) . '-' . now()->format('ymd');
    }
}
