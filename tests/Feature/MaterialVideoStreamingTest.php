<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\Instructor;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 7: video materials are genuinely stored locally on the private
 * disk (mp4/mov/avi accepted uploads, `driver => local` in
 * config/filesystems.php) — HTTP Range streaming is applicable, not
 * exempt, and is implemented in MaterialDownloadController::stream().
 */
class MaterialVideoStreamingTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;
    private CourseMaterial $video;
    private string $content;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        [, $instructor] = $this->instructor();
        $this->course = Course::factory()->create(['title' => 'English', 'status' => 'published', 'instructor_id' => $instructor->id]);

        $this->content = str_repeat('0123456789', 100); // 1000 bytes
        Storage::disk('private')->put('materials/video.mp4', $this->content);

        $this->video = CourseMaterial::create([
            'course_id' => $this->course->id, 'title' => 'Lecture video', 'type' => 'video',
            'file_path' => 'materials/video.mp4', 'disk' => 'private', 'mime_type' => 'video/mp4', 'is_visible' => true,
        ]);
    }

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

    private function enroll(User $student): void
    {
        Registration::create([
            'user_id' => $student->id, 'course_id' => $this->course->id,
            'full_name' => $student->name, 'email' => $student->email, 'phone' => '0500000000', 'status' => 'registered',
        ]);
    }

    public function test_authorized_full_response_without_range_header(): void
    {
        $student = $this->student();
        $this->enroll($student);

        $response = $this->actingAs($student, 'sanctum')->get("/api/materials/{$this->video->id}/stream");

        $response->assertOk();
        $response->assertHeader('Accept-Ranges', 'bytes');
        $response->assertHeader('Content-Length', (string) strlen($this->content));
    }

    public function test_authorized_range_response_returns_206_with_correct_content_range(): void
    {
        $student = $this->student();
        $this->enroll($student);

        $response = $this->actingAs($student, 'sanctum')
            ->withHeaders(['Range' => 'bytes=100-199'])
            ->get("/api/materials/{$this->video->id}/stream");

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 100-199/' . strlen($this->content));
        $response->assertHeader('Content-Length', '100');
    }

    public function test_invalid_range_returns_416(): void
    {
        $student = $this->student();
        $this->enroll($student);

        $response = $this->actingAs($student, 'sanctum')
            ->withHeaders(['Range' => 'bytes=9000-9999'])
            ->get("/api/materials/{$this->video->id}/stream");

        $response->assertStatus(416);
        $response->assertHeader('Content-Range', 'bytes */' . strlen($this->content));
    }

    public function test_unauthorized_student_cannot_stream(): void
    {
        $outsider = $this->student();
        // not enrolled

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/materials/{$this->video->id}/stream")
            ->assertStatus(403);
    }

    public function test_wrong_class_student_cannot_stream_class_scoped_video(): void
    {
        [, $instructor] = $this->instructor();
        $groupA = ClassGroup::create(['course_id' => $this->course->id, 'instructor_id' => $instructor->id, 'level_code' => 'B1', 'name' => 'A', 'capacity' => 20, 'status' => 'active']);
        $groupB = ClassGroup::create(['course_id' => $this->course->id, 'instructor_id' => $instructor->id, 'level_code' => 'B1', 'name' => 'B', 'capacity' => 20, 'status' => 'active']);
        $this->video->update(['class_group_id' => $groupB->id]);

        $studentA = $this->student();
        $this->enroll($studentA);
        ClassGroupStudent::create(['class_group_id' => $groupA->id, 'user_id' => $studentA->id, 'status' => 'assigned', 'assigned_at' => now()]);

        $this->actingAs($studentA, 'sanctum')
            ->getJson("/api/materials/{$this->video->id}/stream")
            ->assertStatus(403);
    }

    public function test_missing_file_returns_404(): void
    {
        $student = $this->student();
        $this->enroll($student);
        $this->video->update(['file_path' => 'materials/does-not-exist.mp4']);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/materials/{$this->video->id}/stream")
            ->assertStatus(404);
    }

    public function test_non_video_material_rejected_with_415(): void
    {
        $student = $this->student();
        $this->enroll($student);
        $pdf = CourseMaterial::create([
            'course_id' => $this->course->id, 'title' => 'Doc', 'type' => 'pdf',
            'file_path' => 'materials/doc.pdf', 'disk' => 'private', 'mime_type' => 'application/pdf', 'is_visible' => true,
        ]);
        Storage::disk('private')->put('materials/doc.pdf', 'fake pdf');

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/materials/{$pdf->id}/stream")
            ->assertStatus(415);
    }
}
