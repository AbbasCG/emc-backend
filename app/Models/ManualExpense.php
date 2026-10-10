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
