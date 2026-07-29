<?php

namespace App\Models;

use App\Exceptions\ProtectedChartOfAccountException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'type',
        'parent_id',
        'is_selectable',
        'is_system',
        'is_deletable',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_selectable' => 'boolean',
        'is_system'     => 'boolean',
        'is_deletable'  => 'boolean',
    ];

    /** Fields that define a system account's identity — never editable once is_system is true. */
    private const PROTECTED_IDENTITY_FIELDS = ['code', 'type'];

    protected static function booted(): void
    {
        static::saving(function (self $account) {
            if (! $account->exists) {
                return;
            }

            $wasSystem = (bool) ($account->getOriginal('is_system') ?? false);
            if (! $wasSystem) {
                return;
            }

            foreach (self::PROTECTED_IDENTITY_FIELDS as $field) {
                if ($account->isDirty($field)) {
                    throw new ProtectedChartOfAccountException(
                        "لا يمكن تعديل الحقل '{$field}' لحساب نظامي محمي."
                    );
                }
            }

            if ($account->isDirty('is_system') && ! $account->is_system) {
                throw new ProtectedChartOfAccountException('لا يمكن إلغاء صفة "حساب نظامي" عن هذا الحساب.');
            }
        });

        static::deleting(function (self $account) {
            if ($account->is_system || ! $account->is_deletable) {
                throw new ProtectedChartOfAccountException(
                    'لا يمكن حذف هذا الحساب لأنه مرتبط بالنظام أو يحتوي على معاملات مالية.'
                );
            }

            if ($account->children()->exists()) {
                throw new ProtectedChartOfAccountException(
                    'لا يمكن حذف هذا الحساب لأنه يحتوي على حسابات فرعية متفرعة منه.'
                );
            }

            // NOTE: the original code attempted to block deletion when the
            // account had rows in `financial_transactions`, but that table
            // has no `account_id` column at all (confirmed against its
            // migration — it's keyed by payment_id/registration_id/user_id,
            // unrelated to this Chart of Accounts table). That check was
            // dead/broken code, not a working safeguard, so it's removed
            // rather than left in place silently failing. There is
            // currently no table linking ledger transactions to `accounts`
            // rows — see the Final Report's "remaining limitations".
        });
    }

    /**
     * Parent account.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Direct children accounts.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id')->orderBy('code', 'asc');
    }

    /**
     * Recursive children accounts.
     */
    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    /**
     * Scope for root level accounts (parent_id IS NULL).
     */
    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id')->orderBy('code', 'asc');
    }

    /**
     * Scope for selectable accounts only.
     */
    public function scopeSelectable($query)
    {
        return $query->where('is_selectable', true);
    }
}
