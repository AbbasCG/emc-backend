<?php

namespace Tests\Unit;

use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\User;
use App\Policies\ClassGroupPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassGroupPolicyTest extends TestCase
{
    use RefreshDatabase;

    private ClassGroupPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ClassGroupPolicy();
    }

    private function instructor(): array
    {
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $profile = Instructor::create(['user_id' => $user->id, 'name' => $user->name, 'title' => 'Instructor']);

        return [$user, $profile];
    }

    private function classGroup(Instructor $instructor, array $overrides = []): ClassGroup
    {
        $course = Course::factory()->create(['status' => 'published', 'instructor_id' => $instructor->id]);

        return ClassGroup::create(array_merge([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1', 'capacity' => 10, 'status' => 'active',
        ], $overrides));
    }

    public function test_owner_instructor_can_view_and_update(): void
    {
        [$user, $instructor] = $this->instructor();
        $group = $this->classGroup($instructor);

        $this->assertTrue($this->policy->view($user, $group));
        $this->assertTrue($this->policy->update($user, $group));
        $this->assertTrue($this->policy->manageStudents($user, $group));
        $this->assertTrue($this->policy->createSession($user, $group));
        $this->assertTrue($this->policy->recordAttendance($user, $group));
    }

    public function test_other_instructor_is_denied(): void
    {
        [, $owner] = $this->instructor();
        [$otherUser, ] = $this->instructor();
        $group = $this->classGroup($owner);

        $this->assertFalse($this->policy->view($otherUser, $group));
        $this->assertFalse($this->policy->update($otherUser, $group));
        $this->assertFalse($this->policy->manageStudents($otherUser, $group));
    }

    public function test_user_without_instructor_profile_is_always_denied(): void
    {
        [, $owner] = $this->instructor();
        $group = $this->classGroup($owner);
        $noProfile = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        // A plain admin role with no linked Instructor row must be denied —
        // this Policy deliberately does not grant a blanket admin bypass
        // (regressing that would reopen a previously-fixed vulnerability).
        $this->assertFalse($this->policy->view($noProfile, $group));
        $this->assertFalse($this->policy->update($noProfile, $group));
        $this->assertFalse($this->policy->delete($noProfile, $group));
        $this->assertFalse($this->policy->manageStudents($noProfile, $group));
    }

    public function test_teaching_actions_denied_on_draft_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $group = $this->classGroup($instructor, ['status' => 'draft']);

        $this->assertFalse($this->policy->createSession($user, $group));
        $this->assertFalse($this->policy->recordAttendance($user, $group));
        $this->assertFalse($this->policy->manageAssignments($user, $group));
        $this->assertFalse($this->policy->manageMaterials($user, $group));
        // Ownership-only abilities remain allowed on draft — it's still editable.
        $this->assertTrue($this->policy->update($user, $group));
        $this->assertTrue($this->policy->manageAnnouncements($user, $group));
    }

    public function test_edit_actions_denied_on_completed_and_archived_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $completed = $this->classGroup($instructor, ['status' => 'completed']);
        $archived = $this->classGroup($instructor, ['status' => 'archived']);

        foreach ([$completed, $archived] as $group) {
            $this->assertFalse($this->policy->updateConfiguration($user, $group));
            $this->assertFalse($this->policy->delete($user, $group));
            $this->assertFalse($this->policy->manageStudents($user, $group));
            $this->assertFalse($this->policy->createSession($user, $group));
            $this->assertFalse($this->policy->recordAttendance($user, $group));
            $this->assertFalse($this->policy->manageAnnouncements($user, $group));
            // view/analytics/export remain available — historical access is allowed.
            $this->assertTrue($this->policy->view($user, $group));
            $this->assertTrue($this->policy->viewAnalytics($user, $group));
            $this->assertTrue($this->policy->export($user, $group));
        }
    }
}
