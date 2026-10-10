<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualRevenue extends Model
{
    use HasFactory;

    protected $fillable = [
        'receipt_no',
        'date',
        'revenue_account_id',
        'deposit_account_id',
        'amount',
        'currency',
        'payer_name',
        'program_id',
        'program_name',
        'description',
        'attachment_path',
        'attachment_name',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date'   => 'date:Y-m-d',
        'amount' => 'decimal:2',
    ];

    protected $appends = [
        'attachment_url',
    ];

    public function getAttachmentUrlAttribute(): ?string
    {
        if (!$this->attachment_path) {
            return null;
        }

        if (str_starts_with($this->attachment_path, 'http://') || str_starts_with($this->attachment_path, 'https://')) {
            return $this->attachment_path;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->attachment_path);
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function depositAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deposit_account_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'program_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(AccountJournalEntry::class, 'manual_revenue_id');
    }
}
