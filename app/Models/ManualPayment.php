<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ManualPayment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'student_id', 'purchasable_type', 'purchasable_id', 'finance_account_id',
        'expected_amount', 'paid_amount', 'difference_amount', 'currency',
        'payment_method', 'external_reference', 'proof_path', 'status',
        'payment_date', 'notes', 'order_id', 'payment_id', 'finance_transaction_id',
        'reviewed_by', 'reviewed_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'expected_amount'   => 'decimal:2',
        'paid_amount'       => 'decimal:2',
        'difference_amount' => 'decimal:2',
        'payment_date'      => 'date',
        'reviewed_at'       => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    public function financeAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function financeTransaction(): BelongsTo
    {
        return $this->belongsTo(FinanceAccountTransaction::class, 'finance_transaction_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
