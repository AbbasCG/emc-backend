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
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }
}
