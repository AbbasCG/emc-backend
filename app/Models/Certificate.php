<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Certificate extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    use Auditable, SoftDeletes;

    // ── Status constants ──────────────────────────────────────────────────────
    // certificate.status — main lifecycle state
    public const STATUS_PENDING            = 'pending';
    public const STATUS_PENDING_GENERATION = 'pending_generation';
    public const STATUS_GENERATION_FAILED  = 'generation_failed';
    public const STATUS_APPROVED           = 'approved';
    public const STATUS_ISSUED             = 'issued';
    public const STATUS_REJECTED           = 'rejected';
    public const STATUS_REVOKED            = 'revoked';

    // certificate.certificate_type — what kind of certificate this is
    public const TYPE_WORKSHOP_ATTENDANCE = 'workshop_attendance';
    public const TYPE_COURSE_COMPLETION   = 'course_completion';

    // certificate.approval_status — two-step approval workflow
    public const APPROVAL_PENDING      = 'pending_approval';
    public const APPROVAL_ADMIN_DONE   = 'admin_approved';
    public const APPROVAL_PROGRAM_DONE = 'program_manager_approved';
    public const APPROVAL_BOTH_DONE    = 'both_approved';
    public const APPROVAL_REJECTED     = 'rejected';

    protected $fillable = [
        'user_id', 'course_id', 'track_id', 'workshop_id',
        'certificate_type', 'title', 'professional_title',
        'verification_code', 'qr_code_path', 'issued_at', 'expires_at',
        'status', 'approved_by', 'metadata',
        // New fields
        'certificate_template_id', 'pdf_path', 'recipient_name', 'recipient_email',
        'certificate_code', 'related_type', 'related_id', 'issued_by',
        'revoked_at', 'revoke_reason', 'batch_id',
        'approval_status', 'workshop_number', 'learning_path_id',
        // PDF tracking
        'pdf_generated_at', 'pdf_file_size', 'pdf_generation_status', 'pdf_last_error', 'template_id_used',
        'pdf_command_retry_count',
    ];

    protected $casts = [
        'issued_at'               => 'datetime',
        'expires_at'              => 'datetime',
        'revoked_at'              => 'datetime',
        'pdf_generated_at'        => 'datetime',
        'metadata'                => 'array',
        'pdf_command_retry_count' => 'integer',
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
                    // Select only code_prefix to avoid loading the full template row
                    $prefix = CertificateTemplate::where('id', $certificate->certificate_template_id)
                        ->value('code_prefix') ?? 'EMC-CR';
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

    public function approvals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CertificateApproval::class);
    }

    public function learningPath(): BelongsTo
    {
        return $this->belongsTo(\App\Models\LearningPath::class, 'learning_path_id');
    }

    public function isFullyApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_BOTH_DONE;
    }

}
