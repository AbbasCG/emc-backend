<?php

namespace Tests\Feature\Finance;

use App\Exceptions\ProtectedFinanceAccountException;
use App\Models\FinanceAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * System-managed Stripe finance accounts (STRIPE_CARD / STRIPE_IDEAL) — must
 * be auto-provisioned, resolvable only by their stable `code`, and immutable
 * (undeletable, technical identity un-editable) at the backend layer, not
 * only hidden from the admin UI.
 */
class SystemPaymentAccountTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_stripe_card_system_account_is_created(): void
    {
        FinanceAccount::ensureSystemAccounts();

        $this->assertDatabaseHas('finance_accounts', [
            'code'           => 'STRIPE_CARD',
            'provider'       => 'stripe',
            'payment_method' => 'card',
            'currency'       => 'EUR',
            'is_system'      => true,
            'is_deletable'   => false,
            'is_active'      => true,
        ]);
    }

    public function test_stripe_ideal_system_account_is_created(): void
    {
        FinanceAccount::ensureSystemAccounts();

        $this->assertDatabaseHas('finance_accounts', [
            'code'           => 'STRIPE_IDEAL',
            'provider'       => 'stripe',
            'payment_method' => 'ideal',
            'currency'       => 'EUR',
            'is_system'      => true,
            'is_deletable'   => false,
            'is_active'      => true,
        ]);
    }

    public function test_provisioning_is_idempotent_running_it_twice_creates_no_duplicates(): void
    {
        FinanceAccount::ensureSystemAccounts();
        FinanceAccount::ensureSystemAccounts();

        $this->assertSame(1, FinanceAccount::where('code', 'STRIPE_CARD')->count());
        $this->assertSame(1, FinanceAccount::where('code', 'STRIPE_IDEAL')->count());
    }

    public function test_the_artisan_command_provisions_both_accounts(): void
    {
        $this->artisan('finance:ensure-system-accounts')->assertExitCode(0);

        $this->assertDatabaseHas('finance_accounts', ['code' => 'STRIPE_CARD']);
        $this->assertDatabaseHas('finance_accounts', ['code' => 'STRIPE_IDEAL']);
    }

    public function test_system_account_cannot_be_deleted_via_the_model(): void
    {
        FinanceAccount::ensureSystemAccounts();
        $account = FinanceAccount::forCode('STRIPE_CARD');

        $this->expectException(ProtectedFinanceAccountException::class);
        $account->delete();
    }

    public function test_backend_rejects_direct_deletion_via_the_api(): void
    {
        FinanceAccount::ensureSystemAccounts();
        $admin = $this->admin();
        $account = FinanceAccount::forCode('STRIPE_IDEAL');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/finance/accounts/{$account->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('finance_accounts', ['id' => $account->id, 'deleted_at' => null]);
    }

    public function test_a_non_system_account_can_still_be_deleted(): void
    {
        $admin = $this->admin();
        $account = FinanceAccount::create([
            'name' => 'Petty Cash', 'type' => 'cash', 'currency' => 'EUR',
            'is_system' => false, 'is_deletable' => true, 'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/finance/accounts/{$account->id}")
            ->assertOk();

        $this->assertSoftDeleted('finance_accounts', ['id' => $account->id]);
    }

    public function test_system_account_code_cannot_be_changed(): void
    {
        FinanceAccount::ensureSystemAccounts();
        $account = FinanceAccount::forCode('STRIPE_CARD');

        $this->expectException(ProtectedFinanceAccountException::class);
        $account->update(['code' => 'SOMETHING_ELSE']);
    }

    public function test_provider_and_payment_method_cannot_be_changed_via_the_api(): void
    {
        FinanceAccount::ensureSystemAccounts();
        $admin = $this->admin();
        $account = FinanceAccount::forCode('STRIPE_IDEAL');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/finance/accounts/{$account->id}", ['type' => 'bank'])
            ->assertStatus(422);

        $this->assertDatabaseHas('finance_accounts', ['id' => $account->id, 'type' => 'payment_gateway']);
    }

    public function test_safe_editable_fields_like_notes_can_still_be_updated_on_a_system_account(): void
    {
        FinanceAccount::ensureSystemAccounts();
        $admin = $this->admin();
        $account = FinanceAccount::forCode('STRIPE_CARD');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/finance/accounts/{$account->id}", ['notes' => 'Primary Stripe card gateway'])
            ->assertOk();

        $this->assertDatabaseHas('finance_accounts', ['id' => $account->id, 'notes' => 'Primary Stripe card gateway']);
    }

    public function test_finance_accounts_endpoint_exposes_system_account_flags(): void
    {
        FinanceAccount::ensureSystemAccounts();
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/finance/accounts')->assertOk();
        $card = collect($response->json('data'))->firstWhere('code', 'STRIPE_CARD');

        $this->assertNotNull($card);
        $this->assertTrue($card['is_system']);
        $this->assertFalse($card['is_deletable']);
        $this->assertSame('card', $card['payment_method']);
    }

    public function test_client_cannot_forge_a_system_account_via_create(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/finance/accounts', [
            'name' => 'Fake System Account', 'type' => 'stripe', 'currency' => 'EUR',
            'is_system' => true, 'is_deletable' => false, 'code' => 'STRIPE_CARD',
        ])->assertCreated();

        $created = FinanceAccount::find($response->json('data.id'));
        $this->assertFalse((bool) $created->is_system);
        $this->assertNull($created->code);
    }
}
