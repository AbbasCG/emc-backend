<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Models\VolunteerPointAward;
use Database\Seeders\ImpersonatePermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\GrantsTicketReviewPermissions;
use Tests\TestCase;

/**
 * Review matrix for the HR tasks page: WHO can READ it and WHO can EXECUTE hr-approve, in each of the three request modes.
 *
 *   read   = GET /api/admin/tasks/pending-hr-approval  +  GET /api/operations/tasks (the history tab)  -> gated by role (admin tier) OR hr_tasks.view
 *   approve = POST /api/admin/tasks/{id}/hr-approve                                                     -> gated by ROLE (admin tier + hr_manager), never by hr_tasks.view
 *
 *   account                      | read pending | read log | approve
 *   reader   (volunteer + view)  | 200          | 200      | 403
 *   hr_no_perm (hr_manager)      | 403          | 403      | 200
 *   hr_with_view (hr_manager+v)  | 200          | 200      | 200
 *   admin                        | 200          | 200      | 200
 *
 * Every row must hold identically for: (1) the account logged in itself (token), (2) a super_admin previewing it with an
 * impersonation TOKEN, (3) a super_admin previewing it through the cookie SESSION. One test per account x mode, so
 * `--testdox` prints the matrix. The detailed behaviour (task states, self-assignment, replay...) lives in HrTasksReadPermissionTest.
 */
class HrTasksAccessMatrixTest extends TestCase
{
    use GrantsTicketReviewPermissions;
    use RefreshDatabase;

    private const KEY = 'hr_tasks.view';

    private const SPA = [
        'Origin' => 'http://localhost:5173',
        'Referer' => 'http://localhost:5173/login',
        'X-EMC-Auth-Mode' => 'cookie',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ImpersonatePermissionSeeder::class]);
    }

    // ═══ The matrix ═════════════════════════════════════════════════════════
    // [account, mode, read pending, read log, approve]

    public static function matrix(): array
    {
        $rows = [
            // account        => [pending, log, approve]
            'reader' => [200, 200, 403],
            'hr_no_perm' => [403, 403, 200],
            'hr_with_view' => [200, 200, 200],
            'admin' => [200, 200, 200],
        ];
        $modes = ['direct login', 'token preview', 'cookie preview'];

        $cases = [];
        foreach ($rows as $account => [$pending, $log, $approve]) {
            foreach ($modes as $mode) {
                $cases["$account via $mode: read pending $pending, read log $log, approve $approve"] = [$account, $mode, $pending, $log, $approve];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function test_access_matrix(string $account, string $mode, int $pending, int $log, int $approve): void
    {
        $target = $this->account($account);
        $task = $this->waitingForHr();

        $go = $this->actingVia($mode, $target);

        $this->assertResult($go('GET', '/api/admin/tasks/pending-hr-approval'), $pending, "$account/$mode read pending");
        $this->assertResult($go('GET', '/api/operations/tasks'), $log, "$account/$mode read log");
        $this->assertResult($go('POST', "/api/admin/tasks/{$task->id}/hr-approve"), $approve, "$account/$mode approve");

        $fresh = $task->fresh();
        if ($approve === 200) {
            $this->assertSame('completed', $fresh->status);
            $this->assertSame($target->id, (int) $fresh->hr_approved_by, 'the approval is recorded for the account, not for the previewer');
            $this->assertNotNull($fresh->hr_approved_at);
            $this->assertSame(1, $this->awards($task), 'exactly one credit');
        } else {
            $this->assertSame('waiting_approval', $fresh->status, 'refused: task untouched');
            $this->assertNull($fresh->hr_approved_by);
            $this->assertNull($fresh->hr_approved_at);
            $this->assertSame(0, $this->awards($task), 'refused: nothing credited');
        }
    }

    // ═══ Fixtures ═══════════════════════════════════════════════════════════

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'password' => bcrypt('secret123')]);
    }

    /** reader = a plain member holding ONLY hr_tasks.view; hr_no_perm / hr_with_view = a real hr_manager without / with it; admin = admin tier. */
    private function account(string $name): User
    {
        $u = match ($name) {
            'reader' => $this->user('volunteer'),
            'hr_no_perm', 'hr_with_view' => $this->user('hr_manager'),
            'admin' => $this->user('admin'),
        };
        if (in_array($name, ['reader', 'hr_with_view'], true)) {
            $this->assignPermission($u, self::KEY, 'allow');
        }

        return $u->fresh();
    }

    /** Fresh task per attempt: manager-approved, waiting for HR, assigned to somebody other than the actor. */
    private function waitingForHr(): Task
    {
        return Task::create([
            'title' => 'مهمة بانتظار HR', 'description' => 'x', 'task_scope' => Task::SCOPE_MEDIUM, 'points' => 100, 'estimated_hours' => 2,
            'assigned_to' => $this->user('student')->id, 'created_by' => $this->user('admin')->id, 'status' => 'waiting_approval',
            'priority' => 'medium', 'manager_approved_at' => now(),
        ]);
    }

    private function awards(Task $t): int
    {
        return VolunteerPointAward::where('source_type', Task::class)->where('source_id', $t->id)->count();
    }

    /**
     * One place for the three request modes. Returns fn(method, uri): TestResponse, acting as $target.
     * The plumbing (token swap, cookie login + impersonate) is the same sequence HrTasksReadPermissionTest exercises.
     */
    private function actingVia(string $mode, User $target): \Closure
    {
        $as = function (string $token): static {
            $this->app['auth']->forgetGuards();

            return $this->withToken($token);
        };

        if ($mode === 'direct login') {
            $token = $target->createToken('api-token')->plainTextToken;

            return fn (string $m, string $uri): TestResponse => $as($token)->json($m, $uri);
        }

        $super = $this->user('super_admin');

        if ($mode === 'token preview') {
            $login = $super->createToken('api-token')->plainTextToken;
            $imp = $as($login)->postJson("/api/admin/impersonate/{$target->id}")->assertOk()->json('token');
            $this->assertNotEmpty($imp);

            return fn (string $m, string $uri): TestResponse => $as($imp)->json($m, $uri);
        }

        // cookie preview: SPA cookie login as the super admin, then impersonate the target inside the session
        $this->withHeaders(self::SPA)->postJson('/api/auth/login', ['email' => $super->email, 'password' => 'secret123'])->assertOk();
        $this->withHeaders(self::SPA)->postJson("/api/admin/impersonate/{$target->id}")->assertOk()->assertJsonPath('token', null);

        return function (string $m, string $uri): TestResponse {
            $this->app['auth']->forgetGuards();

            return $this->withHeaders(self::SPA)->json($m, $uri);
        };
    }

    /** Status must match exactly; a refusal is a JSON 403 (never 404 / redirect / HTML). */
    private function assertResult(TestResponse $r, int $expected, string $label): void
    {
        $r->assertStatus($expected);
        if ($expected === 403) {
            $this->assertStringContainsString('application/json', (string) $r->headers->get('Content-Type'), "$label: refusal is JSON");
            $this->assertIsArray($r->json(), "$label: refusal body decodes as JSON");
            $this->assertNotSame(404, $r->getStatusCode(), "$label: refusal is not a 404");
        }
    }
}
