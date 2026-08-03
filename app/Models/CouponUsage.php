<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponUsage extends Model
{
    public const STATUS_RESERVED  = 'reserved';
    public const STATUS_USED      = 'used';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REVERSED  = 'reversed';
    public const STATUS_EXPIRED   = 'expired';

    protected $fillable = [
        'coupon_id', 'user_id', 'course_id', 'order_id', 'payment_id', 'registration_id',
        'stripe_checkout_session_id', 'original_amount', 'discount_amount', 'final_amount',
        'currency', 'status', 'used_at', 'reversed_at', 'metadata',
    ];

    protected $casts = [
        'original_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_amount'    => 'decimal:2',
        'used_at'         => 'datetime',
        'reversed_at'     => 'datetime',
        'metadata'        => 'array',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
