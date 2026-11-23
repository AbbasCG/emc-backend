<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Models\VolunteerProfile;
use App\Services\TicketAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * WHO A TICKET CAN BE HANDED TO: one rule for the lists and the endpoints, for departments with and WITHOUT
 * technical units.
 *
 * The form metadata (`GET /tickets/meta`), the suggestions and the approve / reassign / bulk validators are all built
 * on TicketAccess::eligibleAssigneesQuery(), so a listed person is never refused and a refused person is never
 * listed. The property tests below send every listed (and every deliberately excluded) person through the REAL
 * endpoints.
 */
class TicketAssigneeEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Department $withUnits;

    private Department $noUnits;

    private Department $other;

    private User $leaderU;

    private User $leaderN;

    private User $leaderO;

    private DepartmentalUnit $unit1;

    private DepartmentalUnit $unit2;

    private DepartmentalUnit $foreignUnit;

    /** @var array<string, User> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The property tests send dozens of bulk requests; the 10/min throttle is not what is under test here.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        [$this->withUnits, $this->leaderU] = $this->dept('قسم بوحدات');
        [$this->noUnits, $this->leaderN] = $this->dept('قسم بلا وحدات');
        [$this->other, $this->leaderO] = $this->dept('قسم آخر');

        $this->unit1 = $this->unit($this->withUnits, 'U1');
        $this->unit2 = $this->unit($this->withUnits, 'U2');
        $this->foreignUnit = $this->unit($this->other, 'F1');

        // Department WITH units.
        $this->u['unitMember1'] = $this->teamMember($this->withUnits, $this->unit1);
        $this->u['unitMember2'] = $this->teamMember($this->withUnits, $this->unit2);
        $this->u['deptOnlyU'] = $this->pivotMember($this->withUnits);
        $this->u['teamNoUnitU'] = $this->teamMember($this->withUnits, null);

        // Department WITHOUT units.
        $this->u['memberN1'] = $this->pivotMember($this->noUnits);
        $this->u['memberN2'] = $this->teamMember($this->noUnits, null);
        $this->u['inactiveN'] = $this->pivotMember($this->noUnits, ['is_active' => false]);
        $this->u['withdrawnN'] = $this->pivotMember($this->noUnits);
        VolunteerProfile::create(['user_id' => $this->u['withdrawnN']->id, 'status' => VolunteerProfile::STATUS_WITHDRAWN]);
        $this->u['onLeaveN'] = $this->pivotMember($this->noUnits);
        VolunteerProfile::create(['user_id' => $this->u['onLeaveN']->id, 'status' => VolunteerProfile::STATUS_ON_LEAVE]);
        $this->u['activeVolunteerN'] = $this->pivotMember($this->noUnits);
        VolunteerProfile::create(['user_id' => $this->u['activeVolunteerN']->id, 'status' => VolunteerProfile::STATUS_ACTIVE]);

        // People who must never be offered or accepted for either of them.
        $this->u['outsider'] = $this->user('student');
        $this->u['otherMember'] = $this->pivotMember($this->other);
        $this->u['globalOutsider'] = $this->user('super_admin');
        $tech = Department::create(['name' => 'Technology', 'name_ar' => 'إدارة التقنية', 'slug' => 'technology', 'status' => 'active', 'is_active' => true]);
        $this->u['techStaff'] = $this->teamMember($tech, null);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function user(string $role = 'student', array $over = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true], $over));
    }

    /** @return array{0: Department, 1: User} */
    private function dept(string $name): array
    {
        $d = Department::create(['name' => $name.Str::random(4), 'name_ar' => $name, 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
        $leader = $this->user('programs_manager');
        $d->update(['leader_id' => $leader->id]);

        return [$d, $leader];
    }

    private function unit(Department $d, string $code, array $over = []): DepartmentalUnit
    {
        return DepartmentalUnit::create(array_merge(['department_id' => $d->id, 'code' => $code.Str::random(3), 'name_ar' => 'وحدة '.$code, 'is_active' => true], $over));
    }

    private function pivotMember(Department $d, array $over = []): User
    {
        $u = $this->user('student', $over);
        $d->users()->attach($u->id);

        return $u;
    }

    private function teamMember(Department $d, ?DepartmentalUnit $unit): User
    {
        $u = $this->user('student');
        $profile = TeamProfile::create(['user_id' => $u->id, 'name_ar' => $u->name, 'position_ar' => 'عضو', 'department_id' => $d->id, 'member_type' => 'staff', 'is_active' => true]);
        TeamMember::create(['user_id' => $u->id, 'team_profile_id' => $profile->id, 'department_id' => $d->id, 'unit_id' => $unit?->id, 'status' => 'active']);

        return $u;
    }

    private function ticket(Department $d, array $over = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-E-'.Str::random(8), 'title' => 'تذكرة', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $d->id, 'created_by_id' => $this->u['outsider']->id, 'created_by_name' => 'مُبلِّغ', 'status' => 'PENDING_APPROVAL',
            // CHANGED: a manager who names THEMSELVES the assignee cannot change the estimate in the same reassign, so these
            // tickets already carry the estimate the requests repeat.
            'task_scope' => 'simple', 'task_points' => 30,
        ], $over));
    }

    private function approve(Ticket $t, User $as, array $body = [])
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/v1/tickets/{$t->id}/approve", array_merge(['sla_hours' => 24, 'task_scope' => 'simple', 'task_points' => 30], $body));
    }

    private function reassign(Ticket $t, User $as, array $body = [])
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/v1/tickets/{$t->id}/reassign", array_merge(['task_scope' => 'simple', 'task_points' => 30], $body));
    }

    private function bulk(array $ids, User $as, array $body = [])
    {
        Sanctum::actingAs($as);

        return $this->postJson('/api/v1/tickets/bulk', array_merge(['action' => 'reassign', 'ticket_ids' => $ids, 'task_scope' => 'simple', 'task_points' => 30], $body));
    }

    /** @return array<int, int> ids listed by the form metadata, sorted */
    private function listed(Department $d, User $as, ?DepartmentalUnit $unit = null): array
    {
        Sanctum::actingAs($as);
        $res = $this->getJson('/api/v1/tickets/meta?department_id='.$d->id.($unit ? '&unit_id='.$unit->id : ''))->assertOk();

        foreach ($res->json('users') as $row) {
            $this->assertSame(['id', 'name', 'role'], array_keys($row), 'only id, name and role leave the server (no e-mail)');
        }

        $ids = array_map('intval', array_column($res->json('users'), 'id'));
        sort($ids);

        return $ids;
    }

    private function ids(User ...$users): array
    {
        $ids = array_map(fn (User $u) => $u->id, $users);
        sort($ids);

        return $ids;
    }

    // ═══ The lists ═══════════════════════════════════════════════════════════

    public function test_a_department_without_units_lists_its_leader_team_members_and_members_only(): void
    {
        $this->assertSame(
            $this->ids($this->leaderN, $this->u['memberN1'], $this->u['memberN2'], $this->u['activeVolunteerN']),
            $this->listed($this->noUnits, $this->leaderN),
            'leader + team members + user_departments members; no inactive, withdrawn, on-leave, outsider, other-department, global-outsider or technology staff',
        );

        Sanctum::actingAs($this->leaderN);
        $meta = $this->getJson('/api/v1/tickets/meta?department_id='.$this->noUnits->id)->assertOk();
        $this->assertFalse($meta->json('has_units'));
        $this->assertSame([], $meta->json('tech_units'));
    }

    public function test_a_department_with_units_keeps_its_rules(): void
    {
        $dept = $this->ids($this->leaderU, $this->u['unitMember1'], $this->u['unitMember2'], $this->u['deptOnlyU'], $this->u['teamNoUnitU']);
        $this->assertSame($dept, $this->listed($this->withUnits, $this->leaderU), 'no unit chosen: the whole department');
        $this->assertSame($this->ids($this->u['unitMember1']), $this->listed($this->withUnits, $this->leaderU, $this->unit1), 'a unit chosen: its exact active members only');
        $this->assertSame($this->ids($this->u['unitMember2']), $this->listed($this->withUnits, $this->leaderU, $this->unit2));

        Sanctum::actingAs($this->leaderU);
        $meta = $this->getJson('/api/v1/tickets/meta?department_id='.$this->withUnits->id)->assertOk();
        $this->assertTrue($meta->json('has_units'));
        $this->assertCount(2, $meta->json('tech_units'));
    }

    public function test_a_unit_of_another_department_lists_nobody(): void
    {
        $this->assertSame([], $this->listed($this->withUnits, $this->leaderU, $this->foreignUnit));
        $this->assertSame([], $this->listed($this->noUnits, $this->leaderN, $this->unit1));
    }

    public function test_the_list_is_only_for_people_who_may_manage_the_department(): void
    {
        $this->assertSame([], $this->listed($this->noUnits, $this->u['memberN1']), 'a plain member is offered nobody');
        $this->assertSame([], $this->listed($this->noUnits, $this->leaderU), 'another department\'s leader neither');
        $this->assertNotSame([], $this->listed($this->noUnits, $this->user('admin')), 'a global role may');
    }

    public function test_a_deactivated_unit_does_not_count_as_a_unit(): void
    {
        $dept = $this->dept('قسم وحدته معطلة')[0];
        $this->unit($dept, 'OFF', ['is_active' => false]);

        $this->assertFalse(app(TicketAccess::class)->departmentHasUnits($dept->id));
        $this->assertTrue(app(TicketAccess::class)->departmentHasUnits($this->withUnits->id));
    }

    // ═══ Property: what is listed is accepted, what is not listed is refused (real endpoints) ═══

    /** @return array<string, array{0: string, 1: ?string}> department key, unit key */
    public static function scopes(): array
    {
        return [
            'department without units' => ['noUnits', null],
            'department with units, no unit' => ['withUnits', null],
            'department with units, unit 1' => ['withUnits', 'unit1'],
            'department with units, unit 2' => ['withUnits', 'unit2'],
        ];
    }

    #[DataProvider('scopes')]
    public function test_every_listed_person_is_accepted_by_approve_and_every_unlisted_person_is_refused(string $deptKey, ?string $unitKey): void
    {
        $dept = $this->{$deptKey};
        $unit = $unitKey ? $this->{$unitKey} : null;
        $leader = $deptKey === 'noUnits' ? $this->leaderN : $this->leaderU;

        $listed = $this->listed($dept, $leader, $unit);
        $this->assertNotSame([], $listed);

        $everyone = array_merge(array_map(fn (User $u) => $u->id, $this->u), [$this->leaderN->id, $this->leaderU->id, $this->leaderO->id]);
        $unlisted = array_values(array_diff($everyone, $listed));

        foreach ($listed as $id) {
            $this->approve($this->ticket($dept), $leader, ['assigned_to_id' => $id] + ($unit ? ['unit_id' => $unit->id] : []))
                ->assertOk()->assertJsonPath('data.assigned_to_id', $id);
        }

        foreach ($unlisted as $id) {
            $t = $this->ticket($dept);
            $this->approve($t, $leader, ['assigned_to_id' => $id] + ($unit ? ['unit_id' => $unit->id] : []))
                ->assertStatus(422)->assertJsonStructure(['errors' => ['assigned_to_id']]);
            $this->assertSame('PENDING_APPROVAL', $t->fresh()->status, 'a refused person changes nothing');
        }
    }

    #[DataProvider('scopes')]
    public function test_the_same_property_holds_for_reassign_bulk_reassign_and_the_suggestions(string $deptKey, ?string $unitKey): void
    {
        $dept = $this->{$deptKey};
        $unit = $unitKey ? $this->{$unitKey} : null;
        $leader = $deptKey === 'noUnits' ? $this->leaderN : $this->leaderU;
        $listed = $this->listed($dept, $leader, $unit);
        $everyone = array_merge(array_map(fn (User $u) => $u->id, $this->u), [$this->leaderN->id, $this->leaderU->id, $this->leaderO->id]);
        $unlisted = array_values(array_diff($everyone, $listed));
        $unitBody = $unit ? ['unit_id' => $unit->id] : [];

        // Reassign (single), then bulk, for every listed person.
        foreach ($listed as $id) {
            $this->reassign($this->ticket($dept, ['status' => 'ASSIGNED']), $leader, ['assigned_to_id' => $id] + $unitBody)->assertOk()->assertJsonPath('data.assigned_to_id', $id);

            $t = $this->ticket($dept, ['status' => 'ASSIGNED']);
            $this->bulk([$t->id], $leader, ['assigned_to_id' => $id] + $unitBody)->assertOk()->assertJsonPath('data.succeeded', 1);
            $this->assertSame($id, (int) $t->fresh()->assigned_to_id);
        }

        foreach ($unlisted as $id) {
            $t = $this->ticket($dept, ['status' => 'ASSIGNED']);
            $this->reassign($t, $leader, ['assigned_to_id' => $id] + $unitBody)->assertStatus(422);
            $res = $this->bulk([$t->id], $leader, ['assigned_to_id' => $id] + $unitBody)->assertOk()->assertJsonPath('data.failed', 1);
            $this->assertFalse($res->json('data.results.0.ok'));
            $this->assertNull($t->fresh()->assigned_to_id, 'nothing was assigned');
        }

        // The suggestions come from the same query (they cannot carry the unit of the request, only the ticket's own).
        $carrier = $this->ticket($dept, ['status' => 'ASSIGNED', 'unit_id' => $unit?->id]);
        Sanctum::actingAs($leader);
        $suggested = collect($this->getJson("/api/v1/tickets/{$carrier->id}/assignment-suggestions")->assertOk()->json('data.candidates'))->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $this->assertSame($listed, $suggested, 'suggestions == the form list == what the endpoints accept');
    }

    public function test_technology_department_staff_are_no_longer_assignable_to_other_departments(): void
    {
        $tech = $this->u['techStaff'];
        $this->assertNotContains($tech->id, $this->listed($this->noUnits, $this->leaderN));

        $t = $this->ticket($this->noUnits);
        $this->approve($t, $this->leaderN, ['assigned_to_id' => $tech->id])->assertStatus(422);
    }

    public function test_global_roles_are_assignable_only_when_they_belong_to_the_department(): void
    {
        $admin = $this->u['globalOutsider'];
        $this->approve($this->ticket($this->noUnits), $this->leaderN, ['assigned_to_id' => $admin->id])->assertStatus(422);

        $this->noUnits->users()->attach($admin->id);
        $this->approve($this->ticket($this->noUnits), $this->leaderN, ['assigned_to_id' => $admin->id])->assertOk();
        $this->assertContains($admin->id, $this->listed($this->noUnits, $this->leaderN));
    }

    public function test_inactive_withdrawn_and_on_leave_people_are_refused_with_a_reason(): void
    {
        $res = $this->approve($this->ticket($this->noUnits), $this->leaderN, ['assigned_to_id' => $this->u['inactiveN']->id])->assertStatus(422);
        $this->assertStringContainsString('غير فعّال', $res->json('message'));

        $res = $this->approve($this->ticket($this->noUnits), $this->leaderN, ['assigned_to_id' => $this->u['withdrawnN']->id])->assertStatus(422);
        $this->assertStringContainsString('منسحب', $res->json('message'));

        $res = $this->approve($this->ticket($this->noUnits), $this->leaderN, ['assigned_to_id' => $this->u['onLeaveN']->id])->assertStatus(422);
        $this->assertStringContainsString('إجازة', $res->json('message'));

        $res = $this->approve($this->ticket($this->noUnits), $this->leaderN, ['assigned_to_id' => $this->u['outsider']->id])->assertStatus(422);
        $this->assertStringContainsString('غير مؤهل', $res->json('message'));

        $res = $this->approve($this->ticket($this->withUnits), $this->leaderU, ['assigned_to_id' => $this->u['unitMember2']->id, 'unit_id' => $this->unit1->id])->assertStatus(422);
        $this->assertStringContainsString('الوحدة', $res->json('message'));
    }

    // ═══ A department without units never needs a unit ═══════════════════════

    public function test_approve_assigns_a_member_of_a_department_without_units_with_no_unit_at_all(): void
    {
        $t = $this->ticket($this->noUnits);

        $this->approve($t, $this->leaderN, ['assigned_to_id' => $this->u['memberN1']->id, 'estimated_hours' => 1.5])->assertOk()
            ->assertJsonPath('data.status', 'ASSIGNED')->assertJsonPath('data.unit_id', null);

        $fresh = $t->fresh();
        $this->assertSame('ASSIGNED', $fresh->status);
        $this->assertSame($this->u['memberN1']->id, (int) $fresh->assigned_to_id);
        $this->assertNull($fresh->unit_id);
    }

    public function test_approve_assigns_a_member_of_a_department_with_units_through_its_unit(): void
    {
        $t = $this->ticket($this->withUnits);

        $this->approve($t, $this->leaderU, ['assigned_to_id' => $this->u['unitMember1']->id, 'unit_id' => $this->unit1->id])->assertOk()
            ->assertJsonPath('data.unit_id', $this->unit1->id);
    }

    public function test_the_assigned_member_sees_the_ticket_exactly_once_in_my_work_then_in_progress_after_accepting(): void
    {
        $member = $this->u['memberN1'];
        $t = $this->ticket($this->noUnits);
        $this->approve($t, $this->leaderN, ['assigned_to_id' => $member->id])->assertOk();
        $this->reassign($t->fresh(), $this->leaderN, ['assigned_to_id' => $member->id])->assertOk(); // routing it to the same person again must not duplicate it

        Sanctum::actingAs($member);
        $rows = collect($this->getJson('/api/v1/tickets/my-work')->assertOk()->json('data'))->where('id', $t->id);
        $this->assertCount(1, $rows);
        $this->assertSame('ASSIGNED', $rows->first()['status']);
        $this->assertTrue($rows->first()['capabilities']['can_accept']);

        $this->postJson("/api/v1/tickets/{$t->id}/accept-task")->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

        $rows = collect($this->getJson('/api/v1/tickets/my-work')->assertOk()->json('data'))->where('id', $t->id);
        $this->assertCount(1, $rows);
        $this->assertSame('IN_PROGRESS', $rows->first()['status']);
    }

    public function test_a_ticket_carrying_a_unit_its_department_no_longer_has_is_still_assignable(): void
    {
        $stale = $this->unit($this->noUnits, 'OLD', ['is_active' => false]);
        $t = $this->ticket($this->noUnits, ['status' => 'ASSIGNED', 'unit_id' => $stale->id, 'assigned_to_id' => $this->u['memberN1']->id, 'task_scope' => 'simple', 'task_points' => 30]);

        $this->reassign($t, $this->leaderN, ['assigned_to_id' => $this->u['memberN2']->id])->assertOk()->assertJsonPath('data.assigned_to_id', $this->u['memberN2']->id);

        Sanctum::actingAs($this->leaderN);
        $meta = $this->getJson('/api/v1/tickets/meta?department_id='.$this->noUnits->id)->assertOk();
        $this->assertSame([], $meta->json('tech_units'), 'the deactivated unit is not offered');
        $this->assertFalse($meta->json('has_units'));
    }

    public function test_a_reassign_that_keeps_the_ticket_unit_judges_the_assignee_by_that_unit(): void
    {
        $t = $this->ticket($this->withUnits, ['status' => 'ASSIGNED', 'unit_id' => $this->unit1->id, 'assigned_to_id' => $this->u['unitMember1']->id, 'task_scope' => 'simple', 'task_points' => 30]);

        $this->reassign($t, $this->leaderU, ['assigned_to_id' => $this->u['unitMember2']->id])->assertStatus(422);
        $this->reassign($t, $this->leaderU, ['assigned_to_id' => $this->u['unitMember2']->id, 'unit_id' => $this->unit2->id])->assertOk();
    }

    // ═══ A unit of another department, and the actor ═════════════════════════

    public function test_a_unit_of_another_department_is_refused_everywhere(): void
    {
        $t = $this->ticket($this->noUnits);
        $this->approve($t, $this->leaderN, ['assigned_to_id' => $this->u['memberN1']->id, 'unit_id' => $this->foreignUnit->id])
            ->assertStatus(422)->assertJsonPath('errors.unit_id.0', 'الوحدة المحددة لا تتبع إدارة هذه التذكرة.');
        $this->approve($t, $this->leaderN, ['unit_id' => $this->unit1->id])->assertStatus(422)->assertJsonStructure(['errors' => ['unit_id']]);

        $assigned = $this->ticket($this->noUnits, ['status' => 'ASSIGNED']);
        $this->reassign($assigned, $this->leaderN, ['assigned_to_id' => $this->u['memberN1']->id, 'unit_id' => $this->unit1->id])->assertStatus(422)->assertJsonStructure(['errors' => ['unit_id']]);

        $res = $this->bulk([$assigned->id], $this->leaderN, ['assigned_to_id' => $this->u['memberN1']->id, 'unit_id' => $this->unit1->id])->assertOk();
        $this->assertSame(1, $res->json('data.failed'));
        $this->assertStringContainsString('الوحدة', $res->json('data.results.0.message'));

        $this->assertSame('PENDING_APPROVAL', $t->fresh()->status);
        $this->assertNull($assigned->fresh()->assigned_to_id);
    }

    public function test_only_the_leader_or_a_global_role_may_assign_on_every_path_including_bulk(): void
    {
        $t = $this->ticket($this->noUnits);
        $assigned = $this->ticket($this->noUnits, ['status' => 'ASSIGNED']);
        $body = ['assigned_to_id' => $this->u['memberN1']->id];

        foreach ([$this->u['memberN2'], $this->u['memberN1'], $this->u['outsider']] as $notLeader) {
            $this->approve($t, $notLeader, $body)->assertForbidden();
            $this->reassign($assigned, $notLeader, $body)->assertForbidden();
        }
        // The leader of ANOTHER department is a stranger here.
        $this->approve($t, $this->leaderO, $body)->assertForbidden();
        $this->reassign($assigned, $this->leaderO, $body)->assertForbidden();
        $this->approve($t, $this->leaderU, $body)->assertForbidden();

        foreach ([$this->u['memberN2'], $this->leaderO, $this->u['outsider']] as $notLeader) {
            $res = $this->bulk([$assigned->id], $notLeader, $body)->assertStatus(200);
            $this->assertSame(1, $res->json('data.failed'), 'bulk checks the actor per ticket');
            $this->assertFalse($res->json('data.results.0.ok'));
        }

        $this->assertSame('PENDING_APPROVAL', $t->fresh()->status);
        $this->assertNull($assigned->fresh()->assigned_to_id);

        // A global role may.
        $this->approve($t, $this->user('admin'), $body)->assertOk();
        $this->bulk([$assigned->id], $this->user('super_admin'), $body)->assertOk()->assertJsonPath('data.succeeded', 1);
    }

    public function test_bulk_mixes_departments_and_each_ticket_is_judged_on_its_own(): void
    {
        $a = $this->ticket($this->noUnits, ['status' => 'ASSIGNED']);
        $b = $this->ticket($this->withUnits, ['status' => 'ASSIGNED']);

        // memberN1 belongs to the department without units only.
        $res = $this->bulk([$a->id, $b->id], $this->user('admin'), ['assigned_to_id' => $this->u['memberN1']->id])->assertOk();

        $this->assertSame(1, $res->json('data.succeeded'));
        $this->assertSame(1, $res->json('data.failed'));
        $this->assertSame($this->u['memberN1']->id, (int) $a->fresh()->assigned_to_id);
        $this->assertNull($b->fresh()->assigned_to_id);
    }
}
