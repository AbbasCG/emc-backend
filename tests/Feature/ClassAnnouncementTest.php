<?php

namespace Tests\Feature;

use App\Models\ClassAnnouncement;
use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\PlatformNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 1 Phase 6: minimal class announcement system. Announcements are
 * class-scoped, draft-hidden-from-students, published-visible-only-to-active-
 * members, and publishing dispatches a notification to those members only.
 */
class ClassAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function instructor(): array
    {
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $profile = Instructor::create(['user_id' => $user->id, 'name' => $user->name, 'title' => 'Instructor']);

        return [$user, $profile];
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    private function course(Instructor $instructor): Course
    {
        return Course::factory()->create([
            'title' => 'English Course', 'status' => 'published', 'instructor_id' => $instructor->id,
        ]);
    }

    private function classGroup(Course $course, Instructor $instructor, array $overrides = []): ClassGroup
    {
        return ClassGroup::create(array_merge([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1 Class 01', 'capacity' => 20, 'status' => 'active',
        ], $overrides));
    }

    private function assignToClass(ClassGroup $group, User $student, string $status = 'assigned'): void
    {
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'status' => $status, 'assigned_at' => now(),
        ]);
    }

    public function test_instructor_can_create_and_publish_announcement(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);

        $create = $this->actingAs($user, 'sanctum')->postJson("/api/instructor/classes/{$group->id}/announcements", [
            'title' => 'اختبار الأسبوع القادم', 'body' => 'سيكون هناك اختبار الأسبوع القادم.',
        ]);
        $create->assertStatus(201);
        $id = $create->json('data.id');

        $this->assertDatabaseHas('class_announcements', ['id' => $id, 'status' => 'draft']);

        $publish = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/instructor/classes/{$group->id}/announcements/{$id}/publish");
        $publish->assertOk();

        $this->assertDatabaseHas('class_announcements', ['id' => $id, 'status' => 'published']);
    }

    public function test_draft_announcement_is_not_visible_to_students(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $member = $this->student();
        $this->assignToClass($group, $member);

        ClassAnnouncement::create([
            'course_id' => $course->id, 'class_group_id' => $group->id, 'created_by' => $user->id,
            'title' => 'Draft', 'body' => 'B', 'status' => 'draft',
        ]);

        $response = $this->actingAs($member, 'sanctum')->getJson("/api/student/classes/{$group->id}/announcements");
        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    public function test_published_announcement_visible_only_to_active_class_members(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B']);

        $memberA = $this->student();
        $this->assignToClass($groupA, $memberA);
        $memberB = $this->student();
        $this->assignToClass($groupB, $memberB);
        $removedFromA = $this->student();
        $this->assignToClass($groupA, $removedFromA, 'removed');

        $announcement = ClassAnnouncement::create([
            'course_id' => $course->id, 'class_group_id' => $groupA->id, 'created_by' => $user->id,
            'title' => 'Announcement A', 'body' => 'B', 'status' => 'published', 'published_at' => now(),
        ]);

        $this->actingAs($memberA, 'sanctum')
            ->getJson("/api/student/classes/{$groupA->id}/announcements")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Not a member of class A — no route to see it via A's endpoint.
        $this->actingAs($memberB, 'sanctum')
            ->getJson("/api/student/classes/{$groupA->id}/announcements")
            ->assertStatus(403);

        // Was removed from class A — no longer an active member.
        $this->actingAs($removedFromA, 'sanctum')
            ->getJson("/api/student/classes/{$groupA->id}/announcements")
            ->assertStatus(403);
    }

    public function test_publishing_notifies_only_active_class_members(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $member = $this->student();
        $this->assignToClass($group, $member);
        $outsider = $this->student(); // not in this class at all

        $create = $this->actingAs($user, 'sanctum')->postJson("/api/instructor/classes/{$group->id}/announcements", [
            'title' => 'T', 'body' => 'B',
        ]);
        $id = $create->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/instructor/classes/{$group->id}/announcements/{$id}/publish")
            ->assertOk();

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $member->id, 'type' => 'class_announcement_published',
        ]);
        $this->assertDatabaseMissing('platform_notifications', [
            'user_id' => $outsider->id, 'type' => 'class_announcement_published',
        ]);
    }

    public function test_instructor_cannot_manage_announcements_for_another_instructors_class(): void
    {
        [, $ownerInstructor] = $this->instructor();
        [$otherUser, ] = $this->instructor();
        $course = $this->course($ownerInstructor);
        $group = $this->classGroup($course, $ownerInstructor);

        $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/instructor/classes/{$group->id}/announcements", ['title' => 'T', 'body' => 'B'])
            ->assertStatus(403);
    }

    public function test_cannot_create_announcement_for_completed_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor, ['status' => 'completed']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/classes/{$group->id}/announcements", ['title' => 'T', 'body' => 'B'])
            ->assertStatus(422);
    }

    public function test_archive_soft_deletes_announcement(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $announcement = ClassAnnouncement::create([
            'course_id' => $course->id, 'class_group_id' => $group->id, 'created_by' => $user->id,
            'title' => 'T', 'body' => 'B', 'status' => 'draft',
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/instructor/classes/{$group->id}/announcements/{$announcement->id}")
            ->assertOk();

        $this->assertDatabaseHas('class_announcements', ['id' => $announcement->id, 'status' => 'archived']);
    }
}
