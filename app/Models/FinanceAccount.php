<?php

namespace App\Models;

use App\Exceptions\ProtectedFinanceAccountException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceAccount extends Model
{
    use SoftDeletes;

    /** Stable codes for the system-provisioned Stripe gateway accounts — see ensureSystemAccounts(). */
    public const CODE_STRIPE_CARD  = 'STRIPE_CARD';
    public const CODE_STRIPE_IDEAL = 'STRIPE_IDEAL';

    protected $fillable = [
        'code', 'name', 'type', 'currency',
        'provider', 'payment_method', 'is_default_for_provider',
        'is_system', 'is_deletable',
        'opening_balance', 'current_balance',
        'notes', 'is_active', 'created_by', 'updated_by',
        'bank_name', 'account_holder', 'iban', 'account_number',
    ];

    protected $casts = [
        'opening_balance'          => 'decimal:2',
        'current_balance'          => 'decimal:2',
        'is_active'                => 'boolean',
        'is_default_for_provider'  => 'boolean',
        'is_system'                => 'boolean',
        'is_deletable'              => 'boolean',
    ];

    /** Fields that define a system account's technical identity — never editable once set on a system account. */
    private const PROTECTED_IDENTITY_FIELDS = ['code', 'provider', 'payment_method', 'type'];

    protected static function booted(): void
    {
        static::saving(function (self $account) {
            // A brand-new record (e.g. from ensureSystemAccounts()/the seeder)
            // is never "changing" anything — only guard real updates.
            if (! $account->exists) {
                return;
            }

            $wasSystem = (bool) ($account->getOriginal('is_system') ?? false);
            if (! $wasSystem) {
                return;
            }

            foreach (self::PROTECTED_IDENTITY_FIELDS as $field) {
                if ($account->isDirty($field)) {
                    throw new ProtectedFinanceAccountException(
                        "The '{$field}' field of a system finance account cannot be changed."
                    );
                }
            }

            // Flipping is_system off would silently disable every protection
            // above on the next save — never allowed through a normal update.
            if ($account->isDirty('is_system') && ! $account->is_system) {
                throw new ProtectedFinanceAccountException('A system finance account cannot be un-marked as system.');
            }

            if ($account->isDirty('currency') && $account->transactions()->exists()) {
                throw new ProtectedFinanceAccountException(
                    'Currency cannot be changed on an account that already has transactions.'
                );
            }
        });

        static::deleting(function (self $account) {
            if (! $account->is_deletable) {
                throw new ProtectedFinanceAccountException(
                    'This account is linked to a payment gateway and cannot be deleted.'
                );
            }
        });
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceAccountTransaction::class);
    }

    /**
     * Resolve the active finance account for a given provider and currency.
     * Prefers the account marked is_default_for_provider; falls back to the first active match.
     *
     * @deprecated Ambiguous once a provider has multiple payment methods
     * (e.g. Stripe Card vs Stripe iDEAL both provider=stripe) — prefer
     * forCode() with the resolved payment-method-specific system account code.
     */
    public static function forProvider(string $provider, string $currency): ?self
    {
        $currency = strtoupper($currency);
        return static::where('provider', $provider)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->orderByDesc('is_default_for_provider')
            ->first();
    }

    /** Resolve a system finance account by its stable code (e.g. STRIPE_CARD). */
    public static function forCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    /**
     * Idempotently create (or leave untouched if already present) the
     * system-managed Stripe gateway finance accounts. Safe to call on every
     * deploy/seed — matched by the stable `code`, never a fixed numeric ID.
     */
    public static function ensureSystemAccounts(): void
    {
        static::firstOrCreate(
            ['code' => self::CODE_STRIPE_CARD],
            [
                'name'           => 'Stripe – Card Payments',
                'type'           => 'payment_gateway',
                'provider'       => 'stripe',
                'payment_method' => 'card',
                'currency'       => 'EUR',
                'is_system'      => true,
                'is_deletable'   => false,
                'is_active'      => true,
                'opening_balance'=> 0,
                'current_balance'=> 0,
            ],
        );

        static::firstOrCreate(
            ['code' => self::CODE_STRIPE_IDEAL],
            [
                'name'           => 'Stripe – iDEAL / Wero Payments',
                'type'           => 'payment_gateway',
                'provider'       => 'stripe',
                'payment_method' => 'ideal',
                'currency'       => 'EUR',
                'is_system'      => true,
                'is_deletable'   => false,
                'is_active'      => true,
                'opening_balance'=> 0,
                'current_balance'=> 0,
            ],
        );
    }
}
