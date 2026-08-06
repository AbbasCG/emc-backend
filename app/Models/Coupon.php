<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use SoftDeletes;

    public const ELIGIBILITY_ALL_PAID_COURSES = 'all_paid_courses';
    public const ELIGIBILITY_SELECTED_COURSES = 'selected_courses';

    protected $fillable = [
        'code', 'name', 'description', 'discount_type', 'discount_value',
        'maximum_discount_amount', 'minimum_order_amount', 'currency',
        'max_uses', 'used_count', 'usage_limit_per_user',
        'first_order_only', 'individual_use_only',
        'starts_at', 'expires_at', 'status',
        'eligibility_type', 'applies_to_type', 'applies_to_id',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'starts_at'                => 'datetime',
        'expires_at'                => 'datetime',
        'discount_value'            => 'decimal:2',
        'maximum_discount_amount'   => 'decimal:2',
        'minimum_order_amount'      => 'decimal:2',
        'first_order_only'          => 'boolean',
        'individual_use_only'       => 'boolean',
    ];

    /** @deprecated legacy single-target pin — no longer written by any current flow; kept for backward compatibility with any historical row. */
    public function appliesTo(): MorphTo
    {
        return $this->morphTo('applies_to');
    }

    /** Courses this coupon is explicitly scoped to (when eligibility_type = selected_courses) or excluded from. */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'coupon_courses')
            ->wherePivot('excluded', false)
            ->withTimestamps();
    }

    public function excludedCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'coupon_courses')
            ->wherePivot('excluded', true)
            ->withTimestamps();
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Legacy, coarse validity check — still used by the existing admin
     * `CouponController::validate()` preview action. The real checkout path
     * uses CouponValidationService, which additionally enforces course
     * eligibility, per-user limits, minimum order amount, and locks the row
     * against concurrent redemption. Kept for backward compatibility.
     */
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

    /** @deprecated float-based legacy calculation — CouponValidationService::calculateDiscount() is cents-safe and authoritative for real checkouts. */
    public function calculateDiscount(float $price): float
    {
        if ($this->discount_type === 'percentage') {
            $discount = round($price * ((float) $this->discount_value / 100), 2);
        } else {
            $discount = min((float) $this->discount_value, $price);
        }

        if ($this->maximum_discount_amount !== null) {
            $discount = min($discount, (float) $this->maximum_discount_amount);
        }

        return $discount;
    }

    /** Derived status — draft/active/inactive are stored; scheduled/expired are derived from dates without contradicting the stored value. */
    public function derivedStatus(): string
    {
        if (in_array($this->status, ['inactive', 'draft', 'archived'], true)) {
            return $this->status;
        }
        $now = now();
        if ($this->expires_at && $now->gt($this->expires_at)) {
            return 'expired';
        }
        if ($this->starts_at && $now->lt($this->starts_at)) {
            return 'scheduled';
        }

        return 'active';
    }
}
