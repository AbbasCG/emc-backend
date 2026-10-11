<?php

namespace Tests\Feature\Finance;

use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessRiskLevel;
use App\Support\PageAccess\RoleDefaultMap;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * The expense / revenue voucher screens are two ADMIN_ONLY page-access
 * capabilities defaulted to admin and finance_manager only. Page access only
 * shows the screen — the API is authorized separately by policy
 * (see ManualVoucherTest).
 */
class ManualVoucherPageAccessTest extends TestCase
{
    public function test_both_capabilities_exist_with_their_routes_and_permissions(): void
    {
        $expenses = PageAccessCatalog::find('finance.expenses');
        $revenues = PageAccessCatalog::find('finance.revenues');

        $this->assertNotNull($expenses);
        $this->assertNotNull($revenues);
        $this->assertSame('/dashboard/finance/expenses', $expenses->primaryRoute);
        $this->assertSame('/dashboard/finance/revenues', $revenues->primaryRoute);
        $this->assertSame(PageAccessRiskLevel::AdminOnly, $expenses->riskLevel);
        $this->assertSame(PageAccessRiskLevel::AdminOnly, $revenues->riskLevel);
        $this->assertFalse($expenses->delegatable);
        $this->assertFalse($revenues->delegatable);
        $this->assertSame(Permissions::MANAGE_PAYMENTS, $expenses->requiredPermission);
        $this->assertSame(Permissions::VIEW_REVENUE, $revenues->requiredPermission);
        $this->assertSame($expenses, PageAccessCatalog::findByRoute('/dashboard/finance/expenses'));
        $this->assertSame($revenues, PageAccessCatalog::findByRoute('/dashboard/finance/revenues'));
    }

    public function test_admin_and_finance_manager_have_the_defaults(): void
    {
        foreach (['admin', 'finance_manager'] as $role) {
            $this->assertContains('finance.expenses', RoleDefaultMap::forRole($role), $role);
            $this->assertContains('finance.revenues', RoleDefaultMap::forRole($role), $role);
        }
    }

    public function test_no_other_role_receives_the_defaults(): void
    {
        foreach (RoleDefaultMap::roles() as $role) {
            if (in_array($role, ['admin', 'finance_manager'], true)) {
                continue;
            }
            $this->assertNotContains('finance.expenses', RoleDefaultMap::forRole($role), $role);
            $this->assertNotContains('finance.revenues', RoleDefaultMap::forRole($role), $role);
        }

        foreach (['student', 'instructor', 'volunteer'] as $role) {
            $this->assertNotContains('finance.expenses', RoleDefaultMap::forRole($role), $role);
            $this->assertNotContains('finance.revenues', RoleDefaultMap::forRole($role), $role);
        }
    }
}
