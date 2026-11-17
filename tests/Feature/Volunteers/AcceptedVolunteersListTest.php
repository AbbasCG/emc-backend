<?php

namespace Tests\Feature\Volunteers;

use App\Models\User;
use App\Models\VolunteerRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/admin/volunteers/accepted lists EVERY accepted volunteer, converted or not: a converted one must not vanish from the page.
 */
class AcceptedVolunteersListTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/volunteers/accepted';

    private function staff(string $role = 'hr_manager'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function vr(string $status, array $over = []): VolunteerRequest
    {
        static $n = 0;
        $n++;

        return VolunteerRequest::create(array_merge([
            'full_name' => "متقدم {$n}", 'email' => "v{$n}@example.com", 'phone' => '+31600000000', 'country' => 'NL', 'city' => 'Amsterdam',
            'gender' => 'female', 'desired_department' => 'التقنية', 'availability' => 'weekends', 'status' => $status,
            'accepted_at' => now(),
        ], $over));
    }

    private function list(array $query = [], ?User $as = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as ?? $this->staff(), 'sanctum')->getJson(self::URL . ($query ? '?' . http_build_query($query) : ''));
    }

    private function ids($res): array
    {
        return collect($res->json('data.data'))->pluck('id')->sort()->values()->all();
    }

    public function test_a_converted_request_still_appears_and_is_flagged(): void
    {
        $pending   = $this->vr('accepted');
        $converted = $this->vr('converted_to_member', ['converted_to_member_at' => now()]);
        $this->vr('pending');
        $this->vr('rejected');

        $res = $this->list()->assertOk();

        $this->assertSame(collect([$pending->id, $converted->id])->sort()->values()->all(), $this->ids($res), 'accepted + converted only');

        $rows = collect($res->json('data.data'))->keyBy('id');
        $this->assertTrue($rows[$converted->id]['is_converted']);
        $this->assertFalse($rows[$converted->id]['can_convert_to_member']);
        $this->assertFalse($rows[$pending->id]['is_converted']);
        $this->assertTrue($rows[$pending->id]['can_convert_to_member']);
    }

    public function test_the_counters(): void
    {
        $this->vr('accepted');
        $this->vr('accepted');
        $this->vr('converted_to_member', ['converted_to_member_at' => now()]);
        $this->vr('accepted', ['accepted_at' => now()->subMonths(2)]);
        $this->vr('converted_to_member', ['accepted_at' => now()->subMonths(3), 'converted_to_member_at' => now()->subMonths(3)]);
        $this->vr('pending', ['accepted_at' => null]);
        $this->vr('rejected', ['accepted_at' => now()]);

        $meta = $this->list()->assertOk()->json('meta');

        $this->assertSame(3, $meta['total_accepted'], 'still waiting for conversion');
        $this->assertSame(2, $meta['total_converted']);
        $this->assertSame(3, $meta['accepted_this_month'], 'counts accepted AND converted, this month only');
    }

    public function test_top_department_counts_both_statuses_and_ignores_blanks(): void
    {
        $this->vr('accepted', ['desired_department' => 'الإعلام']);
        $this->vr('converted_to_member', ['desired_department' => 'التقنية']);
        $this->vr('converted_to_member', ['desired_department' => 'التقنية']);
        $this->vr('accepted', ['desired_department' => '']);
        $this->vr('accepted', ['desired_department' => '']);
        $this->vr('accepted', ['desired_department' => '']);
        $this->vr('rejected', ['desired_department' => 'القانون']);
        $this->vr('rejected', ['desired_department' => 'القانون']);
        $this->vr('rejected', ['desired_department' => 'القانون']);
        $this->vr('rejected', ['desired_department' => 'القانون']);

        $this->assertSame('التقنية', $this->list()->assertOk()->json('meta.top_department'));
    }

    public function test_filters_and_search_work_on_converted_rows(): void
    {
        $a = $this->vr('converted_to_member', ['desired_department' => 'التقنية', 'city' => 'Riyadh', 'full_name' => 'خالد المحوّل']);
        $b = $this->vr('accepted', ['desired_department' => 'التقنية', 'city' => 'Jeddah']);
        $c = $this->vr('accepted', ['desired_department' => 'الإعلام', 'city' => 'Riyadh']);

        $this->assertSame([$a->id, $b->id], $this->ids($this->list(['desired_department' => 'التقنية'])->assertOk()));
        $this->assertSame([$a->id, $c->id], $this->ids($this->list(['city' => 'Riyadh'])->assertOk()));
        $this->assertSame([$a->id], $this->ids($this->list(['desired_department' => 'التقنية', 'city' => 'Riyadh'])->assertOk()));
        $this->assertSame([$a->id], $this->ids($this->list(['search' => 'المحوّل'])->assertOk()));
    }

    public function test_meta_filters_hold_the_stored_values_and_ignore_the_request_filters(): void
    {
        $this->vr('accepted', ['desired_department' => 'الإعلام', 'city' => 'Riyadh', 'availability' => 'weekends']);
        $this->vr('converted_to_member', ['desired_department' => 'التقنية', 'city' => 'Amsterdam', 'availability' => 'evenings']);
        $this->vr('accepted', ['desired_department' => 'التقنية', 'city' => 'Riyadh', 'availability' => 'weekends']);
        $this->vr('accepted', ['desired_department' => '', 'city' => '', 'availability' => null]);
        $this->vr('rejected', ['desired_department' => 'القانون', 'city' => 'Cairo', 'availability' => 'mornings']);
        $this->vr('pending', ['desired_department' => 'المالية', 'city' => 'Doha', 'availability' => 'nights']);

        $expected = $this->list()->assertOk()->json('meta.filters');

        $sorted = fn (array $v) => tap($v, fn (&$x) => sort($x));
        $this->assertSame($sorted(['التقنية', 'الإعلام']), $expected['departments']);
        $this->assertSame(['Amsterdam', 'Riyadh'], $expected['cities']);
        $this->assertSame(['evenings', 'weekends'], $expected['availabilities']);

        // narrowing the list does not narrow the dropdown values
        $narrowed = $this->list(['city' => 'Riyadh', 'desired_department' => 'الإعلام', 'search' => 'nobody-matches'])->assertOk();
        $this->assertSame([], $this->ids($narrowed));
        $this->assertSame($expected, $narrowed->json('meta.filters'));
    }

    public function test_meta_filters_are_capped_at_200(): void
    {
        foreach (range(1, 205) as $i) {
            $this->vr('accepted', ['city' => 'City' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
        }

        $this->assertCount(200, $this->list()->assertOk()->json('meta.filters.cities'));
    }

    public function test_the_response_shape_is_kept(): void
    {
        $this->vr('accepted');

        $this->list()->assertOk()
            ->assertJsonStructure(['success', 'data' => ['data', 'meta', 'links'], 'meta' => ['total_accepted', 'total_converted', 'accepted_this_month', 'top_department', 'filters' => ['departments', 'cities', 'availabilities']]]);
    }

    public function test_the_role_gate_is_unchanged(): void
    {
        $this->vr('accepted');

        $this->app['auth']->forgetGuards();
        $this->getJson(self::URL)->assertStatus(401);

        foreach (['student', 'volunteer', 'department_member', 'instructor', 'executive_admin'] as $role) {
            $this->list([], $this->staff($role))->assertStatus(403);
        }
        foreach (['hr_manager', 'admin', 'super_admin', 'tech_admin'] as $role) {
            $this->list([], $this->staff($role))->assertOk();
        }
    }
}
