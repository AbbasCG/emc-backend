<?php

namespace Tests\Feature;

use App\Jobs\SendLoggedEmailJob;
use App\Jobs\SendPlatformNotificationJob;
use App\Mail\OralAssessmentBookingConfirmedMail;
use App\Mail\OralAssessmentMeetingLinkUpdatedMail;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\NotificationLog;
use App\Models\OralAssessmentBooking;
use App\Models\OralAssessmentSlot;
use App\Models\PlacementTest;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OralAssessmentCommunicationTest extends TestCase
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

    private function enroll(User $student, Course $course): void
    {
        Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email,
            'phone' => '0500000000', 'status' => 'registered',
        ]);
    }

    private function attempt(User $student, Course $course): PlacementTestAttempt
    {
        $test = PlacementTest::create([
            'title' => 'English Placement', 'language' => 'en',
            'duration_minutes' => 30, 'total_questions' => 70, 'is_active' => true,
        ]);

        return PlacementTestAttempt::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'placement_test_id' => $test->id,
            'score' => 42, 'total_questions' => 70, 'percentage' => 60.0,
            'estimated_level' => 'B1', 'status' => 'completed', 'submitted_at' => now(),
        ]);
    }

    private function futureSlot(Instructor $instructor, Course $course, ?string $meetingLink = null): OralAssessmentSlot
    {
        return OralAssessmentSlot::create([
            'instructor_id' => $instructor->id, 'course_id' => $course->id,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30),
            'booking_status' => 'available', 'is_booked' => false,
            'meeting_link' => $meetingLink,
        ]);
    }

    private function bookedBooking(User $student, Instructor $instructor, Course $course, ?string $meetingLink = null): OralAssessmentBooking
    {
        $attempt = $this->attempt($student, $course);
        $slot = $this->futureSlot($instructor, $course, $meetingLink);
        $slot->update(['is_booked' => true, 'booked_by' => $student->id, 'booking_status' => 'booked']);

        return OralAssessmentBooking::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'placement_test_attempt_id' => $attempt->id, 'slot_id' => $slot->id,
            'status' => OralAssessmentBooking::STATUS_BOOKED,
        ]);
    }

    public function test_booking_creates_calendar_events_for_student_and_instructor_and_queues_emails(): void
    {
        Queue::fake();

        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $attempt = $this->attempt($student, $course);
        $slot = $this->futureSlot($instructor, $course, 'https://meet.example.com/abc');

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/oral-assessment/book", [
                'slot_id' => $slot->id,
            ]);

        $response->assertCreated();
        $booking = OralAssessmentBooking::where('user_id', $student->id)->where('course_id', $course->id)->firstOrFail();

        $studentEvent = CalendarEvent::where('eventable_type', OralAssessmentBooking::class)
            ->where('eventable_id', $booking->id)->where('user_id', $student->id)->first();
        $instructorEvent = CalendarEvent::where('eventable_type', OralAssessmentBooking::class)
            ->where('eventable_id', $booking->id)->where('user_id', $instructorUser->id)->first();

        $this->assertNotNull($studentEvent);
        $this->assertNotNull($instructorEvent);
        $this->assertSame('https://meet.example.com/abc', $studentEvent->meeting_url);
        $this->assertSame('https://meet.example.com/abc', $instructorEvent->meeting_url);

        Queue::assertPushed(SendLoggedEmailJob::class, 2);
        Queue::assertPushed(SendLoggedEmailJob::class, fn ($job) => $job->mailable instanceof OralAssessmentBookingConfirmedMail && !$job->mailable->isInstructor);
        Queue::assertPushed(SendLoggedEmailJob::class, fn ($job) => $job->mailable instanceof OralAssessmentBookingConfirmedMail && $job->mailable->isInstructor);

        $this->assertSame(2, NotificationLog::where('type', 'oral_assessment_booking_confirmed')->count());
    }

    public function test_booking_twice_does_not_create_duplicate_calendar_events(): void
    {
        Queue::fake();

        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $booking = $this->bookedBooking($student, $instructor, $course, 'https://meet.example.com/xyz');

        app(\App\Services\OralAssessmentSchedulingService::class)->bookingConfirmed($booking);
        app(\App\Services\OralAssessmentSchedulingService::class)->bookingConfirmed($booking);

        $this->assertSame(
            2,
            CalendarEvent::where('eventable_type', OralAssessmentBooking::class)
                ->where('eventable_id', $booking->id)->count(),
        );
    }

    public function test_instructor_updates_meeting_link_notifies_student_and_syncs_calendar(): void
    {
        Queue::fake();

        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $booking = $this->bookedBooking($student, $instructor, $course, null);

        app(\App\Services\OralAssessmentSchedulingService::class)->bookingConfirmed($booking);

        $response = $this->actingAs($instructorUser, 'sanctum')
            ->patchJson("/api/instructor/oral-bookings/{$booking->id}/meeting-link", [
                'meeting_link' => 'https://meet.example.com/new-link',
            ]);

        $response->assertOk();
        $this->assertSame('https://meet.example.com/new-link', $booking->slot->fresh()->meeting_link);

        $studentEvent = CalendarEvent::where('eventable_type', OralAssessmentBooking::class)
            ->where('eventable_id', $booking->id)->where('user_id', $student->id)->first();
        $this->assertSame('https://meet.example.com/new-link', $studentEvent->meeting_url);

        Queue::assertPushed(SendLoggedEmailJob::class, fn ($job) => $job->mailable instanceof OralAssessmentMeetingLinkUpdatedMail);

        Queue::assertPushed(
            SendPlatformNotificationJob::class,
            fn ($job) => $job->type === 'oral_assessment_meeting_link_updated' && in_array($student->id, $job->userIds, true),
        );
    }

    public function test_cancelling_a_booking_deletes_its_calendar_events(): void
    {
        Queue::fake();

        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $booking = $this->bookedBooking($student, $instructor, $course, 'https://meet.example.com/abc');
        app(\App\Services\OralAssessmentSchedulingService::class)->bookingConfirmed($booking);

        $this->assertGreaterThan(0, CalendarEvent::where('eventable_type', OralAssessmentBooking::class)
            ->where('eventable_id', $booking->id)->count());

        $response = $this->actingAs($instructorUser, 'sanctum')
            ->patchJson("/api/instructor/oral-bookings/{$booking->id}/status", [
                'status' => 'cancelled_by_instructor',
            ]);

        $response->assertOk();
        $this->assertSame(0, CalendarEvent::where('eventable_type', OralAssessmentBooking::class)
            ->where('eventable_id', $booking->id)->count());
    }

    public function test_reschedule_updates_calendar_events_to_new_time(): void
    {
        Queue::fake();

        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $booking = $this->bookedBooking($student, $instructor, $course, 'https://meet.example.com/abc');
        app(\App\Services\OralAssessmentSchedulingService::class)->bookingConfirmed($booking);

        $newSlot = $this->futureSlot($instructor, $course, 'https://meet.example.com/abc');

        $response = $this->actingAs($instructorUser, 'sanctum')
            ->postJson("/api/instructor/oral-bookings/{$booking->id}/reschedule", [
                'new_slot_id' => $newSlot->id,
            ]);

        $response->assertOk();

        $studentEvent = CalendarEvent::where('eventable_type', OralAssessmentBooking::class)
            ->where('eventable_id', $booking->id)->where('user_id', $student->id)->first();
        $this->assertNotNull($studentEvent);
        $this->assertTrue($studentEvent->start_at->equalTo($newSlot->starts_at));
    }

    public function test_instructor_cannot_update_meeting_link_for_another_instructors_booking(): void
    {
        [$instructorUser, $instructor] = $this->instructor();
        [$otherInstructorUser, $otherInstructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $booking = $this->bookedBooking($student, $instructor, $course);

        $response = $this->actingAs($otherInstructorUser, 'sanctum')
            ->patchJson("/api/instructor/oral-bookings/{$booking->id}/meeting-link", [
                'meeting_link' => 'https://meet.example.com/hijack',
            ]);

        $response->assertForbidden();
        $this->assertNull($booking->slot->fresh()->meeting_link);
    }

    public function test_student_cannot_call_instructor_meeting_link_endpoint(): void
    {
        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $booking = $this->bookedBooking($student, $instructor, $course);

        $response = $this->actingAs($student, 'sanctum')
            ->patchJson("/api/instructor/oral-bookings/{$booking->id}/meeting-link", [
                'meeting_link' => 'https://meet.example.com/hijack',
            ]);

        $response->assertStatus(403);
    }

    public function test_placement_status_endpoint_exposes_meeting_link_only_to_the_booked_student(): void
    {
        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $otherStudent = $this->student();
        $this->enroll($student, $course);
        $this->enroll($otherStudent, $course);
        $booking = $this->bookedBooking($student, $instructor, $course, 'https://meet.example.com/secret');

        $response = $this->actingAs($student, 'sanctum')->getJson("/api/student/courses/{$course->id}/placement-test/status");
        $response->assertOk();
        $this->assertSame('https://meet.example.com/secret', $response->json('data.placement_progress.oral_assessment.meeting_link'));

        // A different student has no booking of their own — no route exposes this booking's link to them.
        $otherResponse = $this->actingAs($otherStudent, 'sanctum')->getJson("/api/student/courses/{$course->id}/placement-test/status");
        $otherResponse->assertOk();
        $this->assertNull($otherResponse->json('data.placement_progress.oral_assessment'));
    }
}
