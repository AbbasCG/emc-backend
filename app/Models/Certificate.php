<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Certificate extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'user_id', 'course_id', 'track_id', 'workshop_id',
        'certificate_type', 'title', 'professional_title',
        'verification_code', 'qr_code_path', 'issued_at', 'expires_at',
        'status', 'approved_by', 'metadata',
    ];

    protected $casts = [
        'issued_at'  => 'datetime',
        'expires_at' => 'datetime',
        'metadata'   => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $certificate) {
            if (empty($certificate->verification_code)) {
                $certificate->verification_code = strtoupper(Str::random(12));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
