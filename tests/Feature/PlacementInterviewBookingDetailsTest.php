<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\OralAssessmentSlot;
use App\Models\PlacementTest;
use App\Models\PlacementTestAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlacementInterviewBookingDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function instructor(): array
    {
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $profile = Instructor::create(['user_id' => $user->id, 'name' => $user->name, 'title' => 'Instructor']);

        return [$user, $profile];
    }

    private function course(Instructor $instructor): Course
    {
        return Course::factory()->create([
            'title' => 'English Course', 'status' => 'published', 'instructor_id' => $instructor->id,
        ]);
    }

    private function placementTest(): PlacementTest
    {
        return PlacementTest::create([
            'title' => 'English Placement', 'language' => 'en',
            'duration_minutes' => 30, 'total_questions' => 70, 'is_active' => true,
        ]);
    }

    /** Creates a booked slot + booking + student + placement attempt, all linked. */
    private function bookedSlot(Instructor $instructor, Course $course): array
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $attempt = PlacementTestAttempt::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'placement_test_id' => $this->placementTest()->id,
            'score' => 42, 'total_questions' => 70, 'percentage' => 60.0,
            'estimated_level' => 'B1', 'status' => 'completed', 'submitted_at' => now(),
        ]);
        $slot = OralAssessmentSlot::create([
            'instructor_id' => $instructor->id, 'course_id' => $course->id,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30),
            'booking_status' => 'booked', 'is_booked' => true, 'booked_by' => $student->id,
        ]);
        $booking = OralAssessmentBooking::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'placement_test_attempt_id' => $attempt->id, 'slot_id' => $slot->id,
            'status' => OralAssessmentBooking::STATUS_BOOKED,
        ]);

        return [$student, $slot, $booking];
    }

    public function test_instructor_sees_student_and_placement_data_on_booked_slot(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        [$student, $slot, $booking] = $this->bookedSlot($instructor, $course);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/availability?upcoming=0');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $slot->id);
        $this->assertNotNull($row['booking']);
        $this->assertSame($student->name, $row['booking']['student']['name']);
        $this->assertSame($student->email, $row['booking']['student']['email']);
        $this->assertSame(42, $row['booking']['placement']['score']);
        $this->assertSame('B1', $row['booking']['placement']['estimated_level']);
        $this->assertSame(OralAssessmentBooking::STATUS_BOOKED, $row['booking']['status']);
        $this->assertNotEmpty($row['booking']['reference']);
    }

    public function test_available_slots_do_not_expose_student_data(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        OralAssessmentSlot::create([
            'instructor_id' => $instructor->id, 'course_id' => $course->id,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30),
            'booking_status' => 'available', 'is_booked' => false,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/availability?upcoming=0');
        $response->assertOk();

        $row = collect($response->json('data'))->first();
        $this->assertNull($row['booking']);
    }

    public function test_instructor_cannot_see_or_act_on_another_instructors_booking(): void
    {
        [$ownerUser, $owner] = $this->instructor();
        [$otherUser, $other] = $this->instructor();
        $course = $this->course($owner);
        [, , $booking] = $this->bookedSlot($owner, $course);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/instructor/oral-bookings/{$booking->id}")
            ->assertStatus(403);

        $this->actingAs($otherUser, 'sanctum')
            ->patchJson("/api/instructor/oral-bookings/{$booking->id}/status", ['status' => 'completed'])
            ->assertStatus(403);
    }

    public function test_status_change_is_recorded_in_history_and_releases_slot_on_cancel(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        [, $slot, $booking] = $this->bookedSlot($instructor, $course);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/instructor/oral-bookings/{$booking->id}/status", [
                'status' => 'cancelled_by_instructor',
                'reason' => 'Instructor unavailable',
            ]);

        $response->assertOk();
        $booking->refresh();
        $slot->refresh();

        $this->assertSame('cancelled_by_instructor', $booking->status);
        $this->assertSame('available', $slot->booking_status);
        $this->assertFalse((bool) $slot->is_booked);
        $this->assertDatabaseHas('oral_assessment_booking_status_histories', [
            'oral_assessment_booking_id' => $booking->id,
            'from_status' => 'booked',
            'to_status'   => 'cancelled_by_instructor',
            'reason'      => 'Instructor unavailable',
        ]);
    }

    public function test_message_endpoint_delivers_in_platform_notification(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        [$student, , $booking] = $this->bookedSlot($instructor, $course);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/oral-bookings/{$booking->id}/message", [
                'body'       => 'Please bring your ID to the interview.',
                'send_email' => false,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $student->id,
            'type'    => 'oral_assessment_message',
        ]);
    }

    public function test_message_notification_includes_the_meeting_url_for_a_booked_slot(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        [$student, $slot, $booking] = $this->bookedSlot($instructor, $course);
        $slot->update(['meeting_link' => 'https://meet.google.com/abc-defg-hij']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/oral-bookings/{$booking->id}/message", [
                'body'       => 'رابط المقابلة',
                'send_email' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('platform_notifications', [
            'user_id'  => $student->id,
            'type'     => 'oral_assessment_message',
            'meta_url' => 'https://meet.google.com/abc-defg-hij',
        ]);
    }

    public function test_message_notification_does_not_leak_meeting_url_for_a_cancelled_booking(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        [$student, $slot, $booking] = $this->bookedSlot($instructor, $course);
        $slot->update(['meeting_link' => 'https://meet.google.com/abc-defg-hij']);
        $booking->update(['status' => 'cancelled_by_instructor']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/oral-bookings/{$booking->id}/message", [
                'body'       => 'تم إلغاء الموعد',
                'send_email' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('platform_notifications', [
            'user_id'  => $student->id,
            'type'     => 'oral_assessment_message',
            'meta_url' => null,
        ]);
    }

    public function test_unsafe_url_scheme_is_never_stored_as_meta_url(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        [$student, $slot, $booking] = $this->bookedSlot($instructor, $course);
        $slot->update(['meeting_link' => 'javascript:alert(1)']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/oral-bookings/{$booking->id}/message", [
                'body'       => 'رابط المقابلة',
                'send_email' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('platform_notifications', [
            'user_id'  => $student->id,
            'type'     => 'oral_assessment_message',
            'meta_url' => null,
        ]);
    }

    public function test_reschedule_releases_old_slot_and_reserves_new_slot_atomically(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        [, $oldSlot, $booking] = $this->bookedSlot($instructor, $course);

        $newSlot = OralAssessmentSlot::create([
            'instructor_id' => $instructor->id, 'course_id' => $course->id,
            'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(30),
            'booking_status' => 'available', 'is_booked' => false,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/oral-bookings/{$booking->id}/reschedule", [
                'new_slot_id' => $newSlot->id,
                'note'        => 'Moved per instructor request',
            ]);

        $response->assertOk();
        $booking->refresh();
        $oldSlot->refresh();
        $newSlot->refresh();

        $this->assertSame($newSlot->id, $booking->slot_id);
        $this->assertSame('rescheduled', $booking->status);
        $this->assertSame('available', $oldSlot->booking_status);
        $this->assertFalse((bool) $oldSlot->is_booked);
        $this->assertTrue((bool) $newSlot->is_booked);
        $this->assertSame('booked', $newSlot->booking_status);
    }

    public function test_double_booking_via_reschedule_is_prevented(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        [, , $bookingA] = $this->bookedSlot($instructor, $course);
        [, $slotB, ] = $this->bookedSlot($instructor, $course); // already booked slot

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/oral-bookings/{$bookingA->id}/reschedule", [
                'new_slot_id' => $slotB->id,
            ]);

        $response->assertStatus(422);
    }
}
