<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests the enrollment-gated material download endpoint.
 *
 * Route: GET /api/materials/{material}/download
 * Logic: auth:sanctum required + caller must be enrolled in the course
 *        OR be an admin/instructor assigned to the course.
 */
class MaterialDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;
    private CourseMaterial $material;

    protected function setUp(): void
    {
        parent::setUp();

        // MaterialDownloadController resolves the disk via `$material->disk ?? 'private'`
        // — fake the same disk the controller actually reads from, not an unrelated one.
        Storage::fake('private');

        $this->course = Course::factory()->free()->create();

        $fakefile = UploadedFile::fake()->create('lecture.pdf', 100, 'application/pdf');
        $path     = Storage::disk('private')->putFileAs('materials', $fakefile, 'lecture.pdf');

        $this->material = CourseMaterial::create([
            'course_id'  => $this->course->id,
            'title'      => 'Lecture Notes',
            'type'       => 'document',
            'file_path'  => $path,
            'disk'       => 'private',
            'is_visible' => true,
        ]);
    }

    // ─── Unauthenticated ──────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_download_material(): void
    {
        $this->getJson("/api/materials/{$this->material->id}/download")
            ->assertStatus(401);
    }

    // ─── Non-enrolled student ─────────────────────────────────────────────────

    public function test_non_enrolled_student_cannot_download_material(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/materials/{$this->material->id}/download")
            ->assertStatus(403);
    }

    // ─── Enrolled student ─────────────────────────────────────────────────────

    public function test_enrolled_student_can_download_material(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        Registration::factory()->create([
            'course_id' => $this->course->id,
            'user_id'   => $student->id,
            'email'     => $student->email,
            'status'    => 'Registered',
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->get("/api/materials/{$this->material->id}/download");

        // 200 (streamed file) or 302 (redirect to presigned URL) are both acceptable
        $this->assertContains($response->getStatusCode(), [200, 302],
            "Enrolled student should receive a download, got {$response->getStatusCode()}");
    }

    public function test_student_with_pending_payment_cannot_download_material(): void
    {
        // Pending payment = not yet Registered — access must be denied
        $student = User::factory()->create(['role' => 'student']);

        Registration::factory()->create([
            'course_id' => $this->course->id,
            'user_id'   => $student->id,
            'email'     => $student->email,
            'status'    => 'Pending Payment',
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/materials/{$this->material->id}/download");

        $this->assertContains($response->getStatusCode(), [403, 404],
            "Student with pending payment should not access materials, got {$response->getStatusCode()}");
    }

    // ─── Admin access ─────────────────────────────────────────────────────────

    public function test_admin_can_download_any_material(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->get("/api/materials/{$this->material->id}/download");

        $this->assertContains($response->getStatusCode(), [200, 302],
            "Admin should be able to download any material, got {$response->getStatusCode()}");
    }

    // ─── Non-existent material ────────────────────────────────────────────────

    public function test_download_returns_404_for_nonexistent_material(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/materials/99999/download')
            ->assertStatus(404);
    }

    // ─── Invisible material ───────────────────────────────────────────────────

    public function test_invisible_material_cannot_be_downloaded_by_student(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        // Enroll the student
        Registration::factory()->create([
            'course_id' => $this->course->id,
            'user_id'   => $student->id,
            'email'     => $student->email,
            'status'    => 'Registered',
        ]);

        // Create a hidden material
        $hidden = CourseMaterial::create([
            'course_id'  => $this->course->id,
            'title'      => 'Hidden Notes',
            'type'       => 'document',
            'file_path'  => 'private/materials/hidden.pdf',
            'is_visible' => false,
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/materials/{$hidden->id}/download");

        $this->assertContains($response->getStatusCode(), [403, 404],
            "Invisible material must not be downloadable by students, got {$response->getStatusCode()}");
    }

    // ─── PDF inline preview ───────────────────────────────────────────────────

    public function test_unauthorized_student_cannot_preview_pdf(): void
    {
        $outsider = User::factory()->create(['role' => 'student']);
        // Not enrolled in $this->course.

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/materials/{$this->material->id}/preview")
            ->assertStatus(403);
    }

    public function test_enrolled_student_gets_inline_pdf_preview(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Registration::factory()->create([
            'course_id' => $this->course->id, 'user_id' => $student->id,
            'email' => $student->email, 'status' => 'Registered',
        ]);
        $this->material->update(['mime_type' => 'application/pdf']);

        $response = $this->actingAs($student, 'sanctum')
            ->get("/api/materials/{$this->material->id}/preview");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_preview_returns_415_for_non_pdf_material(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Registration::factory()->create([
            'course_id' => $this->course->id, 'user_id' => $student->id,
            'email' => $student->email, 'status' => 'Registered',
        ]);
        $this->material->update(['mime_type' => 'application/msword']);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/materials/{$this->material->id}/preview")
            ->assertStatus(415);
    }

    public function test_preview_response_never_leaks_raw_storage_path(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Registration::factory()->create([
            'course_id' => $this->course->id, 'user_id' => $student->id,
            'email' => $student->email, 'status' => 'Registered',
        ]);
        $this->material->update(['mime_type' => 'application/pdf']);

        $response = $this->actingAs($student, 'sanctum')
            ->get("/api/materials/{$this->material->id}/preview");

        $this->assertStringNotContainsString($this->material->file_path, (string) $response->headers);
    }
}
