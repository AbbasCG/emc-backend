<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Found by the all-roles HTTP check against a real server:
 *  - a SUSPENDED account's token could still enroll through the public registration endpoint: `optional.sanctum` signs the user in
 *    AFTER the suspended-account check already ran (it saw no user yet);
 *  - for a case id that does not exist, an unauthorized role was answered 404 (route-model binding runs before the role check)
 *    while an existing id answered 403, which let any logged-in account probe which reconciliation ids exist.
 */
class SuspendedAccountAndIdProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function user(string $role, bool $active = true): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => $active]);
    }

    public function test_a_suspended_account_cannot_enroll_but_can_still_browse_the_public_catalog(): void
    {
        $course = Course::factory()->free()->create(['registration_open' => true, 'status' => 'published']);
        $suspended = $this->user('volunteer', false);
        $token = $suspended->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson("/api/courses/{$course->id}/register", [])->assertStatus(403)->assertJsonPath('message', 'Account is suspended.');
        $this->withToken($token)->postJson('/api/register', ['course_id' => $course->id, 'full_name' => 'x', 'email' => 'x@example.test', 'phone' => '+31600000000'])->assertStatus(403);
        $this->assertSame(0, Registration::count());

        // safe requests are served as a guest (no 403 wall on public pages for a token the browser still holds)
        $this->withToken($token)->getJson("/api/courses/{$course->slug}")->assertOk();

        // an active account with the same kind of token enrolls normally
        $active = $this->user('volunteer');
        $this->withToken($active->createToken('t')->plainTextToken)->postJson("/api/courses/{$course->id}/register", [])->assertCreated();
    }

    public function test_an_unauthorized_role_cannot_tell_which_reconciliation_ids_exist(): void
    {
        $url = '/api/finance/payment-reconciliations/987654/resolve';
        $body = ['resolution' => 'closed_without_enrollment', 'note' => 'ملاحظة كافية'];

        foreach (['volunteer', 'student', 'instructor', 'hr_manager', 'marketing_manager'] as $role) {
            $this->actingAs($this->user($role), 'sanctum')->postJson($url, $body)->assertStatus(403);
        }
        // the people who ARE allowed to ask get the honest answer
        $this->actingAs($this->user('finance_manager'), 'sanctum')->postJson($url, $body)->assertStatus(404);
    }
}
