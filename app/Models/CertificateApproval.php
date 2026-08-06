<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateApproval extends Model
{
    // ── Status constants ──────────────────────────────────────────────────────
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    // ── Type constants ────────────────────────────────────────────────────────
    public const TYPE_ADMINISTRATIVE = 'administrative';
    public const TYPE_PROGRAMS       = 'programs';

    protected $fillable = [
        'certificate_id', 'type', 'approved_by', 'approver_role',
        'status', 'notes', 'actioned_at',
        'signature_path', 'stamp_path',
    ];

    protected $casts = [
        'actioned_at' => 'datetime',
    ];

    // ── Relations ──────────────────────────────────────────────────────────────

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isApproved(): bool  { return $this->status === self::STATUS_APPROVED; }
    public function isRejected(): bool  { return $this->status === self::STATUS_REJECTED; }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_ADMINISTRATIVE => 'الإدارة العليا',
            self::TYPE_PROGRAMS       => 'إدارة البرامج',
            default                   => $this->type,
        };
    }
}
