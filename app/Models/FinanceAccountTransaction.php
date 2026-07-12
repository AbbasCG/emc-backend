<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceAccountTransaction extends Model
{
    protected $fillable = [
        'finance_account_id', 'type', 'category',
        'amount', 'currency', 'status', 'description',
        'payment_method', 'reference_type', 'reference_id',
        'receipt_path', 'transaction_date',
        'created_by', 'updated_by',
        'destination_account_id', 'external_reference',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'transaction_date' => 'date',
        'reviewed_at'      => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'destination_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
