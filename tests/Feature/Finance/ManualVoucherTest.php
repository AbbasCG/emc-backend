<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\AccountJournalEntry;
use App\Models\ManualExpense;
use App\Models\ManualRevenue;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionAssignment;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Manual expense (payment) and revenue (receipt) vouchers:
 *   - posting writes one balanced debit/credit pair to the journal;
 *   - attachments live on the private disk and are reachable only through the
 *     authorized, audited download/preview endpoints — never a public URL;
 *   - posted vouchers are immutable (no update, no hard delete);
 *   - every action is authorized by policy, not by page access or role alone;
 *   - only selectable accounts of the right nature can be posted to.
 */
class ManualVoucherTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;
    private Account $bank;
    private Account $expenseAcc;
    private Account $revenueAcc;
    private Account $expenseGroup;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('public');

        $this->expenseGroup = $this->account('33', 'debit', false);
        $this->cash = $this->account('1102', 'debit');
        $this->bank = $this->account('1101', 'debit');
        $this->expenseAcc = $this->account('3301', 'debit');
        $this->revenueAcc = $this->account('4101', 'credit');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function account(string $code, string $type, bool $selectable = true): Account
    {
        return Account::create([
            'code' => $code, 'name_ar' => "حساب {$code}", 'name_en' => "Account {$code}",
            'type' => $type, 'is_selectable' => $selectable,
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function deny(User $user, string $permission): User
    {
        $permissionId = Permission::firstOrCreate(
            ['name' => $permission],
            ['display_name' => $permission, 'group' => 'finance'],
        )->id;

        UserPermissionAssignment::create([
            'user_id' => $user->id, 'permission_id' => $permissionId, 'state' => 'deny',
            'scope_type' => 'department', 'source_type' => 'manual',
        ]);

        return $user->fresh();
    }

    private function expensePayload(array $over = []): array
    {
        return array_merge([
            'date' => '2026-10-01', 'expense_account_id' => $this->expenseAcc->id,
            'payment_account_id' => $this->cash->id, 'amount' => 150.5, 'currency' => 'YER',
            'description' => 'اشتراك إنترنت', 'status' => 'posted',
        ], $over);
    }

    private function revenuePayload(array $over = []): array
    {
        return array_merge([
            'date' => '2026-10-01', 'revenue_account_id' => $this->revenueAcc->id,
            'deposit_account_id' => $this->bank->id, 'amount' => 300, 'currency' => 'USD',
            'payer_name' => 'جهة داعمة', 'description' => 'رسوم دورة', 'status' => 'posted',
        ], $over);
    }

    private function createExpense(array $over = [], ?UploadedFile $file = null): ManualExpense
    {
        $payload = $this->expensePayload($over) + ($file ? ['attachment' => $file] : []);
        $this->actingAs($this->user('finance_manager'), 'sanctum')
            ->post('/api/admin/finance/expenses', $payload, ['Accept' => 'application/json'])
            ->assertCreated();

        return ManualExpense::latest('id')->firstOrFail();
    }

    private function createRevenue(array $over = [], ?UploadedFile $file = null): ManualRevenue
    {
        $payload = $this->revenuePayload($over) + ($file ? ['attachment' => $file] : []);
        $this->actingAs($this->user('finance_manager'), 'sanctum')
            ->post('/api/admin/finance/revenues', $payload, ['Accept' => 'application/json'])
            ->assertCreated();

        return ManualRevenue::latest('id')->firstOrFail();
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('فاتورة.pdf', 120, 'application/pdf');
    }

    // ── create + balanced journal ───────────────────────────────────────────

    public function test_posted_expense_writes_one_balanced_debit_credit_pair(): void
    {
        $expense = $this->createExpense();

        $this->assertMatchesRegularExpression('/^PV-2026-\d{4}$/', $expense->voucher_no);
        $entries = AccountJournalEntry::where('manual_expense_id', $expense->id)->get();
        $this->assertCount(2, $entries);
        $this->assertEquals(150.5, $entries->sum('debit_amount'));
        $this->assertEquals($entries->sum('debit_amount'), $entries->sum('credit_amount'));
        $this->assertSame($this->expenseAcc->id, $entries->firstWhere('entry_type', 'debit')->account_id);
        $this->assertSame($this->cash->id, $entries->firstWhere('entry_type', 'credit')->account_id);
    }

    public function test_posted_revenue_writes_one_balanced_debit_credit_pair(): void
    {
        $revenue = $this->createRevenue();

        $this->assertMatchesRegularExpression('/^RV-2026-\d{4}$/', $revenue->receipt_no);
        $entries = AccountJournalEntry::where('manual_revenue_id', $revenue->id)->get();
        $this->assertCount(2, $entries);
        $this->assertEquals($entries->sum('debit_amount'), $entries->sum('credit_amount'));
        $this->assertSame($this->bank->id, $entries->firstWhere('entry_type', 'debit')->account_id);
        $this->assertSame($this->revenueAcc->id, $entries->firstWhere('entry_type', 'credit')->account_id);
    }

    public function test_draft_has_no_journal_entries_until_posted(): void
    {
        $expense = $this->createExpense(['status' => 'draft']);
        $this->assertSame(0, AccountJournalEntry::where('manual_expense_id', $expense->id)->count());

        $this->actingAs($this->user('admin'), 'sanctum')
            ->putJson("/api/admin/finance/expenses/{$expense->id}", $this->expensePayload(['status' => 'posted']))
            ->assertOk();

        $entries = AccountJournalEntry::where('manual_expense_id', $expense->id)->get();
        $this->assertCount(2, $entries);
        $this->assertEquals($entries->sum('debit_amount'), $entries->sum('credit_amount'));
    }

    public function test_summary_totals_are_kept_per_currency(): void
    {
        $this->createExpense(['currency' => 'YER', 'amount' => 1000]);
        $this->createExpense(['currency' => 'USD', 'amount' => 10]);
        $this->createExpense(['currency' => 'USD', 'amount' => 5, 'status' => 'draft']);

        $summary = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/finance/expenses')->assertOk()->json('summary');

        $byCurrency = collect($summary['by_currency'])->keyBy('currency');
        $this->assertEquals(10, $byCurrency['USD']['total']);
        $this->assertEquals(1000, $byCurrency['YER']['total']);
        $this->assertEquals(1, $byCurrency['USD']['count']);
        $this->assertCount(2, $summary['by_category'], 'one row per account and currency');
        $this->assertArrayNotHasKey('has_attachment', $summary['by_currency'][0]);
    }

    // ── private attachments ─────────────────────────────────────────────────

    public function test_expense_attachment_is_stored_privately_and_never_exposed_as_a_url(): void
    {
        $expense = $this->createExpense([], $this->pdf());

        Storage::disk('private')->assertExists($expense->attachment_path);
        $this->assertStringStartsWith('expense_vouchers/', $expense->attachment_path);
        $this->assertSame([], Storage::disk('public')->allFiles());

        $json = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson("/api/admin/finance/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('data.has_attachment', true)
            ->assertJsonPath('data.attachment_name', 'فاتورة.pdf')
            ->assertJsonMissingPath('data.attachment_url')
            ->assertJsonMissingPath('data.attachment_path')
            ->getContent();
        $this->assertStringNotContainsString('/storage/', $json);

        $list = $this->actingAs($this->user('admin'), 'sanctum')->getJson('/api/admin/finance/expenses')->getContent();
        $this->assertStringNotContainsString('attachment_url', $list);
        $this->assertStringNotContainsString('expense_vouchers/', $list);
    }

    public function test_revenue_attachment_is_stored_privately(): void
    {
        $revenue = $this->createRevenue([], UploadedFile::fake()->image('receipt.png'));

        Storage::disk('private')->assertExists($revenue->attachment_path);
        $this->assertStringStartsWith('receipt_vouchers/', $revenue->attachment_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_attachment_type_and_size_validation_is_kept(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/finance/expenses', $this->expensePayload() + [
                'attachment' => UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('attachment');

        $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/finance/revenues', $this->revenuePayload() + [
                'attachment' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('attachment');

        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_unauthenticated_download_is_rejected(): void
    {
        $expense = $this->createExpense([], $this->pdf());
        $revenue = $this->createRevenue([], $this->pdf());
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/admin/finance/expenses/{$expense->id}/attachment")->assertUnauthorized();
        $this->getJson("/api/admin/finance/expenses/{$expense->id}/attachment/preview")->assertUnauthorized();
        $this->getJson("/api/admin/finance/revenues/{$revenue->id}/attachment")->assertUnauthorized();
    }

    public function test_users_outside_finance_cannot_download(): void
    {
        $expense = $this->createExpense([], $this->pdf());
        $revenue = $this->createRevenue([], $this->pdf());

        foreach (['student', 'instructor', 'volunteer', 'hr_manager'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user, 'sanctum')->getJson("/api/admin/finance/expenses/{$expense->id}/attachment")->assertForbidden();
            $this->actingAs($user, 'sanctum')->getJson("/api/admin/finance/revenues/{$revenue->id}/attachment/preview")->assertForbidden();
        }
    }

    public function test_finance_role_without_the_view_permission_cannot_download(): void
    {
        $expense = $this->createExpense([], $this->pdf());
        $revenue = $this->createRevenue([], $this->pdf());

        // Passes the route's role gate, fails the policy.
        $noPayments = $this->deny($this->user('finance_manager'), Permissions::VIEW_PAYMENTS);
        $this->actingAs($noPayments, 'sanctum')->getJson("/api/admin/finance/expenses/{$expense->id}/attachment")->assertForbidden();

        $noRevenue = $this->deny($this->user('finance_manager'), Permissions::VIEW_REVENUE);
        $this->actingAs($noRevenue, 'sanctum')->getJson("/api/admin/finance/revenues/{$revenue->id}/attachment")->assertForbidden();
    }

    public function test_authorized_download_and_preview_stream_the_file_and_are_audited(): void
    {
        $expense = $this->createExpense([], $this->pdf());
        $admin = $this->user('admin');

        $download = $this->actingAs($admin, 'sanctum')->get("/api/admin/finance/expenses/{$expense->id}/attachment");
        $download->assertOk();
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));

        $preview = $this->actingAs($admin, 'sanctum')->get("/api/admin/finance/expenses/{$expense->id}/attachment/preview");
        $preview->assertOk();
        $this->assertStringContainsString('inline;', $preview->headers->get('Content-Disposition'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.voucher_attachment_downloaded', 'entity_id' => $expense->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.voucher_attachment_previewed', 'entity_id' => $expense->id]);

        $revenue = $this->createRevenue([], $this->pdf());
        $this->actingAs($this->user('finance_manager'), 'sanctum')
            ->get("/api/admin/finance/revenues/{$revenue->id}/attachment")->assertOk();
    }

    public function test_missing_attachment_returns_404(): void
    {
        $expense = $this->createExpense();
        $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson("/api/admin/finance/expenses/{$expense->id}/attachment")->assertNotFound();
    }

    public function test_a_tampered_stored_path_cannot_escape_the_voucher_directory(): void
    {
        Storage::disk('private')->put('secrets/other.pdf', 'secret');
        Storage::disk('private')->put('expense_vouchers/../secrets/other.pdf', 'secret');
        $expense = $this->createExpense([], $this->pdf());
        $admin = $this->user('admin');

        foreach (['../secrets/other.pdf', 'expense_vouchers/../secrets/other.pdf', 'secrets/other.pdf', 'receipt_vouchers/abc.pdf', '/etc/passwd'] as $path) {
            DB::table('manual_expenses')->where('id', $expense->id)->update(['attachment_path' => $path]);
            $this->actingAs($admin, 'sanctum')
                ->getJson("/api/admin/finance/expenses/{$expense->id}/attachment")
                ->assertNotFound();
        }
    }

    public function test_replacing_a_draft_attachment_removes_the_old_private_file(): void
    {
        $expense = $this->createExpense(['status' => 'draft'], $this->pdf());
        $old = $expense->attachment_path;

        $this->actingAs($this->user('admin'), 'sanctum')
            ->post("/api/admin/finance/expenses/{$expense->id}", $this->expensePayload(['status' => 'draft', '_method' => 'PUT']) + [
                'attachment' => UploadedFile::fake()->image('new.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        Storage::disk('private')->assertMissing($old);
        Storage::disk('private')->assertExists($expense->fresh()->attachment_path);
    }

    // ── posted vouchers are immutable ───────────────────────────────────────

    public function test_posted_expense_cannot_be_updated_or_deleted(): void
    {
        $expense = $this->createExpense([], $this->pdf());
        $admin = $this->user('admin');
        $before = AccountJournalEntry::where('manual_expense_id', $expense->id)->orderBy('id')->get()->toArray();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/finance/expenses/{$expense->id}", $this->expensePayload(['amount' => 999]))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/finance/expenses/{$expense->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('manual_expenses', ['id' => $expense->id, 'amount' => 150.5]);
        $this->assertSame($before, AccountJournalEntry::where('manual_expense_id', $expense->id)->orderBy('id')->get()->toArray());
        Storage::disk('private')->assertExists($expense->attachment_path);
    }

    public function test_posted_revenue_cannot_be_updated_or_deleted(): void
    {
        $revenue = $this->createRevenue();
        $admin = $this->user('admin');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/finance/revenues/{$revenue->id}", $this->revenuePayload(['status' => 'draft']))
            ->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/finance/revenues/{$revenue->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('manual_revenues', ['id' => $revenue->id, 'status' => 'posted']);
        $this->assertSame(2, AccountJournalEntry::where('manual_revenue_id', $revenue->id)->count());
    }

    public function test_draft_vouchers_can_be_deleted_with_their_attachment(): void
    {
        $expense = $this->createExpense(['status' => 'draft'], $this->pdf());
        $revenue = $this->createRevenue(['status' => 'draft']);
        $admin = $this->user('admin');

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/admin/finance/expenses/{$expense->id}")->assertOk();
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/admin/finance/revenues/{$revenue->id}")->assertOk();

        $this->assertDatabaseMissing('manual_expenses', ['id' => $expense->id]);
        $this->assertDatabaseMissing('manual_revenues', ['id' => $revenue->id]);
        Storage::disk('private')->assertMissing($expense->attachment_path);
    }

    // ── authorization on every action ───────────────────────────────────────

    public function test_non_finance_roles_are_refused_on_every_endpoint(): void
    {
        $expense = $this->createExpense(['status' => 'draft']);
        $revenue = $this->createRevenue(['status' => 'draft']);

        foreach (['student', 'instructor', 'volunteer'] as $role) {
            $u = $this->user($role);
            $this->actingAs($u, 'sanctum')->getJson('/api/admin/finance/expenses')->assertForbidden();
            $this->actingAs($u, 'sanctum')->getJson("/api/admin/finance/expenses/{$expense->id}")->assertForbidden();
            $this->actingAs($u, 'sanctum')->postJson('/api/admin/finance/expenses', $this->expensePayload())->assertForbidden();
            $this->actingAs($u, 'sanctum')->putJson("/api/admin/finance/expenses/{$expense->id}", $this->expensePayload())->assertForbidden();
            $this->actingAs($u, 'sanctum')->deleteJson("/api/admin/finance/expenses/{$expense->id}")->assertForbidden();
            $this->actingAs($u, 'sanctum')->getJson('/api/admin/finance/revenues')->assertForbidden();
            $this->actingAs($u, 'sanctum')->postJson('/api/admin/finance/revenues', $this->revenuePayload())->assertForbidden();
            $this->actingAs($u, 'sanctum')->deleteJson("/api/admin/finance/revenues/{$revenue->id}")->assertForbidden();
        }

        $this->assertDatabaseHas('manual_expenses', ['id' => $expense->id]);
        $this->assertDatabaseHas('manual_revenues', ['id' => $revenue->id]);
    }

    public function test_policy_blocks_a_finance_role_lacking_the_business_permission(): void
    {
        $expense = $this->createExpense(['status' => 'draft']);

        $noView = $this->deny($this->user('finance_manager'), Permissions::VIEW_PAYMENTS);
        $this->actingAs($noView, 'sanctum')->getJson('/api/admin/finance/expenses')->assertForbidden();
        $this->actingAs($noView, 'sanctum')->getJson("/api/admin/finance/expenses/{$expense->id}")->assertForbidden();

        $noManage = $this->deny($this->user('finance_manager'), Permissions::MANAGE_PAYMENTS);
        $this->actingAs($noManage, 'sanctum')->getJson('/api/admin/finance/expenses')->assertOk();
        $this->actingAs($noManage, 'sanctum')->postJson('/api/admin/finance/expenses', $this->expensePayload())->assertForbidden();
        $this->actingAs($noManage, 'sanctum')->putJson("/api/admin/finance/expenses/{$expense->id}", $this->expensePayload(['status' => 'draft']))->assertForbidden();
        $this->actingAs($noManage, 'sanctum')->deleteJson("/api/admin/finance/expenses/{$expense->id}")->assertForbidden();
        $this->actingAs($noManage, 'sanctum')->postJson('/api/admin/finance/revenues', $this->revenuePayload())->assertForbidden();
        $this->actingAs($noManage, 'sanctum')->getJson('/api/admin/finance/expenses/suggest-voucher-no')->assertForbidden();

        $noRevenue = $this->deny($this->user('finance_manager'), Permissions::VIEW_REVENUE);
        $this->actingAs($noRevenue, 'sanctum')->getJson('/api/admin/finance/revenues')->assertForbidden();

        $this->assertDatabaseHas('manual_expenses', ['id' => $expense->id]);
    }

    public function test_finance_roles_may_list_and_read(): void
    {
        foreach (['admin', 'finance_manager', 'super_admin', 'tech_admin'] as $role) {
            $u = $this->user($role);
            $this->actingAs($u, 'sanctum')->getJson('/api/admin/finance/expenses')->assertOk();
            $this->actingAs($u, 'sanctum')->getJson('/api/admin/finance/revenues')->assertOk();
        }
    }

    // ── account rules ───────────────────────────────────────────────────────

    public function test_invalid_account_types_are_rejected(): void
    {
        $admin = $this->user('admin');

        // Expense side must be a selectable, debit-nature account.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/finance/expenses', $this->expensePayload(['expense_account_id' => $this->revenueAcc->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('expense_account_id');
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/finance/expenses', $this->expensePayload(['expense_account_id' => $this->expenseGroup->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('expense_account_id');
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/finance/expenses', $this->expensePayload(['payment_account_id' => $this->expenseAcc->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('payment_account_id');

        // Revenue side must be a selectable, credit-nature account.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/finance/revenues', $this->revenuePayload(['revenue_account_id' => $this->expenseAcc->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('revenue_account_id');
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/finance/revenues', $this->revenuePayload(['deposit_account_id' => $this->expenseGroup->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('deposit_account_id');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/finance/revenues', $this->revenuePayload(['currency' => 'XXX']))
            ->assertUnprocessable()->assertJsonValidationErrors('currency');

        $this->assertSame(0, ManualExpense::count() + ManualRevenue::count());
        $this->assertSame(0, AccountJournalEntry::count());
    }

    // ── routes ──────────────────────────────────────────────────────────────

    public function test_route_names_are_unique_and_frontend_paths_exist(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())->map->getName()->filter();
        $this->assertSame([], $names->duplicates()->values()->all(), 'duplicate route names');

        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri());
        foreach ([
            'GET|HEAD api/admin/finance/expenses',
            'POST api/admin/finance/expenses',
            'GET|HEAD api/admin/finance/expenses/suggest-voucher-no',
            'GET|HEAD api/admin/finance/expenses/{expense}',
            'PUT|PATCH api/admin/finance/expenses/{expense}',
            'DELETE api/admin/finance/expenses/{expense}',
            'GET|HEAD api/admin/finance/expenses/{expense}/attachment',
            'GET|HEAD api/admin/finance/expenses/{expense}/attachment/preview',
            'GET|HEAD api/admin/finance/revenues',
            'POST api/admin/finance/revenues',
            'GET|HEAD api/admin/finance/revenues/suggest-receipt-no',
            'GET|HEAD api/admin/finance/revenues/{revenue}',
            'PUT|PATCH api/admin/finance/revenues/{revenue}',
            'DELETE api/admin/finance/revenues/{revenue}',
            'GET|HEAD api/admin/finance/revenues/{revenue}/attachment',
            'GET|HEAD api/admin/finance/revenues/{revenue}/attachment/preview',
        ] as $expected) {
            $this->assertContains($expected, $uris, $expected);
        }

        $this->assertFalse($uris->contains(fn ($u) => preg_match('#^\S+ api/finance/(expenses|revenues)#', $u)), 'dead /api/finance voucher routes are gone');
    }
}
