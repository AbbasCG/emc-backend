<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NotificationLog extends Model
{
    /** Emails deliberately not sent: admin switch off, or the recipient turned the channel off. */
    public const SKIPPED_STATUSES = ['skipped_disabled', 'skipped_user_preference'];

    protected $fillable = [
        'uuid', 'user_id', 'triggered_by_user_id',
        'channel', 'type', 'recipient', 'recipient_name', 'subject',
        'status', 'payload', 'error_message', 'smtp_response',
        'sent_at', 'queued_at', 'failed_at', 'delivered_at',
        'mail_driver', 'job_id', 'retry_count', 'execution_time_ms',
        'related_course_id', 'related_session_id',
        'related_ticket_id', 'related_assignment_id',
    ];

    protected $casts = [
        'payload'      => 'array',
        'sent_at'      => 'datetime',
        'queued_at'    => 'datetime',
        'failed_at'    => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            if (empty($log->uuid)) {
                $log->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function relatedCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'related_course_id');
    }
}
