<?php

namespace Tests\Feature\Finance;

use App\Exceptions\ProtectedChartOfAccountException;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root cause (from the prior audit): /admin/finance/chart-of-accounts and
 * /admin/finance/accounts sat inside the broad role:admin,super_admin,
 * tech_admin group (routes/api.php ~709) — finance_manager was never
 * included, and no permission-catalog system was wired to that middleware.
 * Fixed by moving the six Chart of Accounts endpoints into their own
 * role:admin,super_admin,tech_admin,finance_manager group, without adding
 * finance_manager to the broad admin group itself.
 */
class FinanceManagerChartOfAccountsAccessTest extends TestCase
{
    use RefreshDatabase;

    private function financeManager(): User
    {
        return User::factory()->create(['role' => 'finance_manager', 'is_active' => true]);
    }

    private function admin(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function rootAccount(array $overrides = []): Account
    {
        return Account::create(array_merge([
            'code' => '1', 'name_ar' => 'الأصول', 'type' => 'debit', 'is_selectable' => false,
        ], $overrides));
    }

    // 1-3: read access ---------------------------------------------------------

    public function test_finance_manager_can_load_chart_of_accounts_tree(): void
    {
        $this->rootAccount();

        $this->actingAs($this->financeManager(), 'sanctum')
            ->getJson('/api/admin/finance/chart-of-accounts')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_finance_manager_can_list_accounts(): void
    {
        $this->rootAccount();

        $this->actingAs($this->financeManager(), 'sanctum')
            ->getJson('/api/admin/finance/accounts')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_finance_manager_can_request_suggested_code(): void
    {
        $this->actingAs($this->financeManager(), 'sanctum')
            ->getJson('/api/admin/finance/chart-of-accounts/suggest-code')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // 4-5: create/update --------------------------------------------------------

    public function test_finance_manager_can_create_a_normal_account(): void
    {
        $parent = $this->rootAccount();
        $manager = $this->financeManager();

        $response = $this->actingAs($manager, 'sanctum')->postJson('/api/admin/finance/accounts', [
            'code' => '101', 'name_ar' => 'النقدية', 'parent_id' => $parent->id,
        ]);

        $response->assertCreated()->assertJson(['success' => true]);
        $this->assertDatabaseHas('accounts', [
            'code' => '101', 'is_system' => false, 'is_deletable' => true, 'created_by' => $manager->id,
        ]);
    }

    public function test_finance_manager_can_update_a_normal_account(): void
    {
        $account = $this->rootAccount(['is_system' => false]);
        $manager = $this->financeManager();

        $response = $this->actingAs($manager, 'sanctum')->putJson("/api/admin/finance/accounts/{$account->id}", [
            'name_ar' => 'الأصول المحدّثة', 'is_selectable' => true,
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('accounts', [
            'id' => $account->id, 'name_ar' => 'الأصول المحدّثة', 'updated_by' => $manager->id,
        ]);
    }

    // 6-9: delete rules -----------------------------------------------------

    public function test_finance_manager_can_delete_an_empty_deletable_account(): void
    {
        $account = $this->rootAccount(['is_deletable' => true, 'is_selectable' => true]);

        $this->actingAs($this->financeManager(), 'sanctum')
            ->deleteJson("/api/admin/finance/accounts/{$account->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('accounts', ['id' => $account->id]);
    }

    public function test_finance_manager_cannot_delete_a_system_account(): void
    {
        $account = $this->rootAccount(['is_system' => true, 'is_deletable' => false]);

        $response = $this->actingAs($this->financeManager(), 'sanctum')
            ->deleteJson("/api/admin/finance/accounts/{$account->id}");

        $response->assertStatus(422)->assertJson(['success' => false, 'code' => 'finance_account_protected']);
        $this->assertDatabaseHas('accounts', ['id' => $account->id]);
    }

    /**
     * There is no table linking ledger transactions to Chart of Accounts
     * rows today (financial_transactions has no account_id column — see
     * Account::booted() docblock) — so "has transactions" cannot be
     * asserted against real data yet. This test instead proves the
     * documented safeguards (is_deletable=false) still block deletion,
     * which is how a CoA row would be marked once it accrues real
     * financial history in a future iteration of the model.
     */
    public function test_finance_manager_cannot_delete_an_account_marked_not_deletable(): void
    {
        $account = $this->rootAccount(['is_deletable' => false]);

        $response = $this->actingAs($this->financeManager(), 'sanctum')
            ->deleteJson("/api/admin/finance/accounts/{$account->id}");

        $response->assertStatus(422)->assertJson(['success' => false, 'code' => 'finance_account_protected']);
        $this->assertDatabaseHas('accounts', ['id' => $account->id]);
    }

    public function test_finance_manager_cannot_delete_an_account_with_children(): void
    {
        $parent = $this->rootAccount();
        Account::create(['code' => '101', 'name_ar' => 'فرعي', 'type' => 'debit', 'parent_id' => $parent->id, 'is_selectable' => true]);

        $response = $this->actingAs($this->financeManager(), 'sanctum')
            ->deleteJson("/api/admin/finance/accounts/{$parent->id}");

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertDatabaseHas('accounts', ['id' => $parent->id]);
    }

    // 10: system code protection -----------------------------------------------

    public function test_finance_manager_cannot_change_a_system_account_code_via_the_model(): void
    {
        $account = $this->rootAccount(['is_system' => true]);

        $this->expectException(ProtectedChartOfAccountException::class);
        $account->update(['code' => 'CHANGED']);
    }

    // 11-14: role matrix ----------------------------------------------------

    public function test_admin_access_still_works(): void
    {
        $this->actingAs($this->admin('admin'), 'sanctum')
            ->getJson('/api/admin/finance/chart-of-accounts')
            ->assertOk();
    }

    public function test_super_admin_access_still_works(): void
    {
        $this->actingAs($this->admin('super_admin'), 'sanctum')
            ->getJson('/api/admin/finance/chart-of-accounts')
            ->assertOk();
    }

    public function test_tech_admin_access_still_works(): void
    {
        $this->actingAs($this->admin('tech_admin'), 'sanctum')
            ->getJson('/api/admin/finance/chart-of-accounts')
            ->assertOk();
    }

    public function test_unrelated_role_receives_403(): void
    {
        $this->actingAs($this->admin('student'), 'sanctum')
            ->getJson('/api/admin/finance/chart-of-accounts')
            ->assertStatus(403);
    }

    public function test_unrelated_role_is_still_blocked_from_the_broad_admin_group(): void
    {
        // finance_manager must NOT have been granted the broad admin group
        // this fix was explicitly told not to widen.
        $this->actingAs($this->financeManager(), 'sanctum')
            ->getJson('/api/admin/departments')
            ->assertStatus(403);
    }

    // 15: auth ----------------------------------------------------------------

    public function test_unauthenticated_request_receives_401(): void
    {
        $this->getJson('/api/admin/finance/chart-of-accounts')->assertStatus(401);
    }

    // 16: no duplicate routes -----------------------------------------------

    public function test_chart_of_accounts_routes_are_registered_exactly_once(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/finance/chart-of-accounts')
                || $r->uri() === 'api/admin/finance/accounts'
                || $r->uri() === 'api/admin/finance/accounts/{id}')
            ->map(fn ($r) => implode('|', $r->methods()) . ' ' . $r->uri())
            ->values()
            ->all();

        $this->assertSame(count($routes), count(array_unique($routes)), 'Duplicate route registration found: ' . implode(', ', $routes));
        $this->assertNotEmpty($routes);
    }
}
