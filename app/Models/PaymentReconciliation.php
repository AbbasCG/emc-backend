<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An open item for a confirmed payment whose enrollment could not be granted. See the migration for what it is and is not.
 */
class PaymentReconciliation extends Model
{
    public const REASON_INSTRUCTOR_CONFLICT = 'instructor_conflict';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const RESOLUTION_ACTIVATED = 'enrollment_activated';

    public const RESOLUTION_CLOSED = 'closed_without_enrollment';

    /** A to-do marker for money owed back and returned OUTSIDE the platform; never a payment status. */
    public const REFUND_PENDING = 'pending';

    protected $fillable = [
        'payment_id', 'order_id', 'registration_id', 'learning_path_enrollment_id', 'user_id', 'course_id', 'learning_path_id',
        'reason', 'status', 'details', 'resolved_by', 'resolved_at', 'resolution', 'refund_status', 'resolution_note',
    ];

    protected $casts = ['details' => 'array', 'resolved_at' => 'datetime'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
