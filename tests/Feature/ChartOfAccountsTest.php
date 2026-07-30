<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartOfAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => 'admin',
        ]);
    }

    public function test_can_fetch_chart_of_accounts_tree(): void
    {
        $parent = Account::create([
            'code'          => '1',
            'name_ar'       => 'الأصول',
            'name_en'       => 'Assets',
            'type'          => 'debit',
            'is_selectable' => false,
        ]);

        Account::create([
            'code'          => '11',
            'name_ar'       => 'الأصول المتداولة',
            'name_en'       => 'Current Assets',
            'type'          => 'debit',
            'parent_id'     => $parent->id,
            'is_selectable' => false,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/finance/chart-of-accounts');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', '1')
            ->assertJsonPath('data.0.all_children.0.code', '11');
    }

    public function test_creating_child_account_inherits_parent_type(): void
    {
        $parent = Account::create([
            'code'          => '3',
            'name_ar'       => 'المصروفات',
            'name_en'       => 'Expenses',
            'type'          => 'debit',
            'is_selectable' => false,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/admin/finance/accounts', [
                'code'          => '31',
                'name_ar'       => 'مرتبات وأجور',
                'parent_id'     => $parent->id,
                'is_selectable' => false,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'debit');

        $this->assertDatabaseHas('accounts', [
            'code' => '31',
            'type' => 'debit',
        ]);
    }

    public function test_suggest_code_generator(): void
    {
        $parent = Account::create([
            'code'          => '33',
            'name_ar'       => 'مصاريف تشغيلية',
            'type'          => 'debit',
            'is_selectable' => false,
        ]);

        Account::create([
            'code'          => '3301',
            'name_ar'       => 'بدل مواصلات واتصالات',
            'type'          => 'debit',
            'parent_id'     => $parent->id,
            'is_selectable' => true,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/admin/finance/chart-of-accounts/suggest-code?parent_id={$parent->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('suggested_code', '3302');
    }

    public function test_cannot_delete_account_with_children(): void
    {
        $parent = Account::create([
            'code'          => '4',
            'name_ar'       => 'الإيرادات',
            'type'          => 'credit',
            'is_selectable' => false,
        ]);

        Account::create([
            'code'          => '41',
            'name_ar'       => 'إيرادات النشاط التعليمي',
            'type'          => 'credit',
            'parent_id'     => $parent->id,
            'is_selectable' => false,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/admin/finance/accounts/{$parent->id}");

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
