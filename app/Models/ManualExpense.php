<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualExpense extends Model
{
    use HasFactory;

    protected $fillable = [
        'voucher_no',
        'date',
        'expense_account_id',
        'payment_account_id',
        'amount',
        'currency',
        'department_id',
        'department_name',
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

    /**
     * Attachments live on the private disk and are served only through the
     * authorized download endpoint — the storage path is never sent to clients.
     */
    public const ATTACHMENT_DISK = 'private';

    protected $hidden = [
        'attachment_path',
    ];

    protected $appends = [
        'has_attachment',
    ];

    public function getHasAttachmentAttribute(): bool
    {
        return (bool) $this->attachment_path;
    }

    /** Posted vouchers carry journal entries and are immutable. */
    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
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
        return $this->hasMany(AccountJournalEntry::class, 'manual_expense_id');
    }
}
