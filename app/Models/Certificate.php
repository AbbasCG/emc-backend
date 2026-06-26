<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Certificate extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'user_id', 'course_id', 'track_id', 'workshop_id',
        'certificate_type', 'title', 'professional_title',
        'verification_code', 'qr_code_path', 'issued_at', 'expires_at',
        'status', 'approved_by', 'metadata',
        // New fields
        'certificate_template_id', 'pdf_path', 'recipient_name', 'recipient_email',
        'certificate_code', 'related_type', 'related_id', 'issued_by',
        'revoked_at', 'revoke_reason', 'batch_id',
    ];

    protected $casts = [
        'issued_at'   => 'datetime',
        'expires_at'  => 'datetime',
        'revoked_at'  => 'datetime',
        'metadata'    => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $certificate) {
            if (empty($certificate->verification_code)) {
                $certificate->verification_code = strtoupper(Str::random(12));
            }

            if (empty($certificate->certificate_code)) {
                $prefix = 'EMC-CR';
                if ($certificate->certificate_template_id) {
                    $template = CertificateTemplate::find($certificate->certificate_template_id);
                    if ($template) {
                        $prefix = $template->code_prefix;
                    }
                }
                $certificate->certificate_code = app(\App\Services\CertificateService::class)
                    ->generateCode($prefix);
            }
        });
    }

    // ── Relations ──────────────────────────────────────────────────────────────

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

    public function template(): BelongsTo
    {
        return $this->belongsTo(CertificateTemplate::class, 'certificate_template_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CertificateGenerationBatch::class, 'batch_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

}
