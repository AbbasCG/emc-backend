<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the Impact Points ledger. Append-only: there is no update or
 * delete endpoint, and totals are always SUM(points) — never a stored balance —
 * so a correction is a new, attributed row, never an overwrite.
 */
class VolunteerPointAward extends Model
{
    /** فئات المنح — تعكس أبواب سياسة نقاط أثر EMC v1.0. */
    public const CATEGORIES = [
        'meetings', 'task', 'quality_bonus', 'initiative', 'workshop', 'camp',
        'community', 'mentoring', 'knowledge', 'institutional',
        'best_of_department', 'best_of_center', 'exceptional_impact',
        'continuity', 'other',
    ];

    /**
     * Category of a manual correction made through the adjust endpoint. It is
     * deliberately NOT in CATEGORIES, so it can never be chosen on an ordinary
     * award — only the adjustment flow writes it.
     */
    public const CATEGORY_ADJUSTMENT = 'adjustment';

    public const KIND_AWARD = 'award';
    public const KIND_ADJUSTMENT = 'adjustment';
    public const KIND_DEDUCTION = 'deduction';

    protected $fillable = [
        'user_id', 'category', 'points', 'reason', 'note',
        'awarded_by', 'source_type', 'source_id', 'awarded_at',
    ];

    protected $casts = [
        'points'     => 'integer',
        'awarded_at' => 'datetime',
    ];

    /**
     * The internal note is staff-only. It stays hidden everywhere a recipient
     * reads their own ledger; the staff history endpoint calls makeVisible().
     */
    protected $hidden = ['note'];

    protected $appends = ['kind'];

    /**
     * How the row should be presented:
     *   adjustment  a manual correction (either direction) from the adjust flow
     *   deduction   a negative ordinary award
     *   award       a positive ordinary award or an automated grant
     */
    public function getKindAttribute(): string
    {
        if ($this->category === self::CATEGORY_ADJUSTMENT) {
            return self::KIND_ADJUSTMENT;
        }

        return $this->points < 0 ? self::KIND_DEDUCTION : self::KIND_AWARD;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function awarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }
}
