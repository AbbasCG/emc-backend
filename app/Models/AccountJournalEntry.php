<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountJournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'voucher_no',
        'transaction_type',
        'manual_expense_id',
        'manual_revenue_id',
        'account_id',
        'entry_type',
        'debit_amount',
        'credit_amount',
        'currency',
        'date',
        'description',
        'created_by',
    ];

    protected $casts = [
        'date'          => 'date:Y-m-d',
        'debit_amount'  => 'decimal:2',
        'credit_amount' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function manualExpense(): BelongsTo
    {
        return $this->belongsTo(ManualExpense::class, 'manual_expense_id');
    }

    public function manualRevenue(): BelongsTo
    {
        return $this->belongsTo(ManualRevenue::class, 'manual_revenue_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
