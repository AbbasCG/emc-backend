<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Step 4 of the meeting-report wizard: agenda, attendees, decisions, action
 * items and minutes are submitted together with the report and must show up
 * on the meeting detail endpoint in the shape the frontend reads.
 */
class MeetingReportMinutesTest extends TestCase
{
    use RefreshDatabase;

    private function makeMeeting(User $organizer): Meeting
    {
        $dept = Department::create([
            'name' => 'Dept ' . Str::random(6),
            'name_ar' => 'إدارة تجريبية',
            'slug' => 'dept-' . Str::random(8),
            'status' => 'active',
            'is_active' => true,
        ]);

        return Meeting::create([
            'title' => 'اجتماع تجريبي',
            'type' => 'department',
            'status' => 'scheduled',
            'meeting_date' => now(),
            'start_time' => now(),
            'department_id' => $dept->id,
            'organizer_id' => $organizer->id,
        ]);
    }

    public function test_report_saves_minutes_data_and_detail_returns_it(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $meeting = $this->makeMeeting($admin);

        $this->actingAs($admin)->postJson("/api/department/meetings/{$meeting->id}/report", [
            'absentees' => [],
            'team_commitment_score' => 4,
            'agenda' => ['مراجعة الخطة', '  ', 'الميزانية'],
            'attendees' => [['name' => 'أحمد', 'role' => 'مدير'], ['name' => '', 'role' => 'x']],
            'decisions' => [['title' => 'اعتماد الخطة'], ['title' => '']],
            'action_items' => [['title' => 'إعداد التقرير', 'due_date' => '2026-12-01']],
            'minutes' => 'تم الاتفاق على كل البنود.',
        ])->assertOk();

        $detail = $this->actingAs($admin)->getJson("/api/department/meetings/{$meeting->id}")->assertOk();
        fwrite(STDERR, "\nDEBUG:" . json_encode($detail->json(), JSON_UNESCAPED_UNICODE) . "\nDB:" . json_encode($meeting->fresh()->toArray(), JSON_UNESCAPED_UNICODE) . "\n");

        $this->assertSame(['مراجعة الخطة', 'الميزانية'], $detail->json('data.agenda'));
        $this->assertCount(1, $detail->json('data.attendees'));
        $this->assertSame('أحمد', $detail->json('data.attendees.0.name'));
        $this->assertSame('تم الاتفاق على كل البنود.', $detail->json('data.minutes'));
        $this->assertSame('اعتماد الخطة', $detail->json('data.decisions.0.text'));
        $this->assertSame('إعداد التقرير', $detail->json('data.action_items.0.text'));
        $this->assertSame('2026-12-01', $detail->json('data.action_items.0.due_at'));
        $this->assertSame('completed', $detail->json('data.status'));
    }

    public function test_empty_minutes_data_does_not_wipe_existing_values(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $meeting = $this->makeMeeting($admin);
        $meeting->update(['agenda' => ['بند قديم'], 'minutes' => 'محضر قديم']);
        $meeting->decisions()->create(['title' => 'قرار قديم']);

        $this->actingAs($admin)->postJson("/api/department/meetings/{$meeting->id}/report", [
            'agenda' => [], 'decisions' => [], 'action_items' => [], 'minutes' => '',
        ])->assertOk();

        $meeting->refresh();
        $this->assertSame(['بند قديم'], $meeting->agenda);
        $this->assertSame('محضر قديم', $meeting->minutes);
        $this->assertSame(1, $meeting->decisions()->count());
    }
}
