<?php

namespace Tests\Feature;

use App\Jobs\ProcessLargeUploadJob;
use App\Models\UploadRecord;
use App\Models\User;
use App\Services\CompressedUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompressedUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    // ── Image compression flow (profile-based) ────────────────────────────────

    public function test_image_upload_returns_all_metadata_fields(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/course', ['file' => $file])
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => [
                'record_id', 'status', 'original_size', 'optimized_size',
                'saved_percent', 'url', 'thumbnail_url',
            ]])
            ->assertJson(['success' => true, 'data' => ['status' => 'complete']]);
    }

    public function test_stored_file_is_webp_not_the_original_format(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/course', ['file' => $file])
            ->assertOk()
            ->json('data');

        $record = UploadRecord::find($data['record_id']);

        $this->assertStringEndsWith('.webp', $record->path);
        Storage::disk('public')->assertExists($record->path);
    }

    public function test_original_file_is_not_stored_only_optimized(): void
    {
        $user     = User::factory()->create();
        $file     = UploadedFile::fake()->image('big.jpg', 1000, 1000);
        $origName = $file->getClientOriginalName();

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/course', ['file' => $file])
            ->assertOk()
            ->json('data');

        $record = UploadRecord::find($data['record_id']);

        // Stored path uses a hashed name — never the original filename.
        $this->assertStringNotContainsString($origName, $record->path);
        $this->assertNotNull($record->optimized_size);
    }

    public function test_saved_percent_is_non_negative_integer(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('img.jpg', 800, 600);

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/workshop', ['file' => $file])
            ->assertOk()
            ->json('data');

        $this->assertIsInt($data['saved_percent']);
        $this->assertGreaterThanOrEqual(0, $data['saved_percent']);
    }

    // ── Profile: avatar (512×512, 150 KB, thumbnail 128×128) ─────────────────

    public function test_avatar_profile_generates_128x128_thumbnail(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('avatar.jpg', 1000, 800);

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/instructor', ['file' => $file])
            ->assertOk()
            ->json('data');

        $record = UploadRecord::find($data['record_id']);

        $this->assertNotNull($record->thumbnail_path);
        $this->assertNotNull($data['thumbnail_url']);
        Storage::disk('public')->assertExists($record->thumbnail_path);

        // Thumbnail lives under /thumbs/ subfolder.
        $this->assertStringContainsString('/thumbs/', $record->thumbnail_path);
    }

    public function test_student_context_uses_avatar_profile_with_thumbnail(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('photo.png', 600, 600);

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/student', ['file' => $file])
            ->assertOk()
            ->json('data');

        $this->assertNotNull($data['thumbnail_url']);
    }

    public function test_profile_context_uses_avatar_profile(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('me.jpg', 400, 300);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/profile', ['file' => $file])
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['status' => 'complete']]);
    }

    public function test_team_context_uses_avatar_profile(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('member.jpg', 300, 300);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/team', ['file' => $file])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ── Profile: course (1200×675, 500 KB, thumbnail 400×225) ────────────────

    public function test_course_profile_generates_course_thumbnail(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('cover.jpg', 1600, 900);

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/course', ['file' => $file])
            ->assertOk()
            ->json('data');

        $record = UploadRecord::find($data['record_id']);

        $this->assertNotNull($record->thumbnail_path);
        Storage::disk('public')->assertExists($record->thumbnail_path);
    }

    public function test_workshop_context_uses_course_profile_with_thumbnail(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('workshop.jpg', 800, 450);

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/workshop', ['file' => $file])
            ->assertOk()
            ->json('data');

        $this->assertNotNull($data['thumbnail_url']);
    }

    // ── Profile: banner (1920×1080, 1 MB, no thumbnail) ──────────────────────

    public function test_banner_context_produces_no_thumbnail(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('hero.jpg', 1920, 1080);

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/banner', ['file' => $file])
            ->assertOk()
            ->json('data');

        $record = UploadRecord::find($data['record_id']);

        $this->assertNull($record->thumbnail_path);
        $this->assertNull($data['thumbnail_url']);
    }

    public function test_hero_context_uses_banner_profile(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('hero.jpg', 1000, 562);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/hero', ['file' => $file])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ── PDF compression flow ──────────────────────────────────────────────────

    public function test_pdf_upload_succeeds_and_returns_metadata(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('cert.pdf', 512, 'application/pdf');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/certificate', ['file' => $file])
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => [
                'record_id', 'status', 'original_size', 'optimized_size', 'saved_percent', 'url',
            ]])
            ->assertJson(['success' => true, 'data' => ['status' => 'complete']]);
    }

    public function test_pdf_has_no_thumbnail(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('doc.pdf', 200, 'application/pdf');

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/assignment', ['file' => $file])
            ->assertOk()
            ->json('data');

        $this->assertNull($data['thumbnail_url']);
    }

    public function test_pdf_stored_on_private_local_disk(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('report.pdf', 1024, 'application/pdf');

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/certificate', ['file' => $file])
            ->assertOk()
            ->json('data');

        $record = UploadRecord::find($data['record_id']);

        Storage::disk('local')->assertExists($record->path);
        $this->assertSame('local', $record->disk);
    }

    // ── Validation limits ─────────────────────────────────────────────────────

    public function test_image_exceeding_5mb_upload_limit_is_rejected(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('big.jpg', 100, 100)->size(5200); // 5.2 MB

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/course', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_pdf_exceeding_15mb_is_rejected(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('huge.pdf', 15500, 'application/pdf');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/certificate', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_unsupported_mime_type_is_rejected(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('script.exe', 100, 'application/octet-stream');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/course', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_pdf_rejected_in_image_only_context(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('doc.pdf', 200, 'application/pdf');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/course', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_image_rejected_in_pdf_only_context(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/certificate', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_invalid_context_returns_403(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/unknown_context', ['file' => $file])
            ->assertStatus(403);
    }

    // ── Metadata response shape ───────────────────────────────────────────────

    public function test_metadata_fields_have_correct_types(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('test.jpg', 600, 400);

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/course', ['file' => $file])
            ->assertOk()
            ->json('data');

        $this->assertIsInt($data['original_size']);
        $this->assertIsInt($data['optimized_size']);
        $this->assertIsInt($data['saved_percent']);
        $this->assertNotEmpty($data['url']);
        $this->assertGreaterThan(0, $data['original_size']);
        $this->assertGreaterThan(0, $data['optimized_size']);
    }

    public function test_private_context_url_points_to_download_route(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('cert.pdf', 256, 'application/pdf');

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/certificate', ['file' => $file])
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('uploads/download', $data['url']);
    }

    public function test_private_context_with_thumbnail_includes_thumb_query_param(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('img.jpg', 400, 300);

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/assignment', ['file' => $file])
            ->assertOk()
            ->json('data');

        if ($data['thumbnail_url']) {
            $this->assertStringContainsString('thumb=1', $data['thumbnail_url']);
        }
    }

    // ── Deduplication ─────────────────────────────────────────────────────────

    public function test_same_file_hash_returns_existing_record_without_re_storing(): void
    {
        $user    = User::factory()->create();
        $service = app(CompressedUploadService::class);

        $tmpPath = tempnam(sys_get_temp_dir(), 'emc_') . '.jpg';
        $canvas  = imagecreatetruecolor(200, 150);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 120, 180, 240));
        imagejpeg($canvas, $tmpPath, 90);
        imagedestroy($canvas);

        $file1 = new \Illuminate\Http\UploadedFile($tmpPath, 'dup.jpg', 'image/jpeg', null, true);
        $file2 = new \Illuminate\Http\UploadedFile($tmpPath, 'dup.jpg', 'image/jpeg', null, true);

        $r1 = $service->upload($file1, 'course', $user->id);
        $r2 = $service->upload($file2, 'course', $user->id);

        $this->assertSame($r1['url'], $r2['url']);
        $this->assertSame(1, UploadRecord::count());

        @unlink($tmpPath);
    }

    // ── Large file queue dispatch ─────────────────────────────────────────────

    public function test_large_image_is_queued_and_returns_202(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('big.jpg', 100, 100)->size(3500);

        $data = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/course', ['file' => $file])
            ->assertStatus(202)
            ->json('data');

        $this->assertSame('processing', $data['status']);
        $this->assertArrayHasKey('record_id', $data);
        $this->assertArrayHasKey('status_url', $data);

        Queue::assertPushed(ProcessLargeUploadJob::class);
    }

    public function test_large_pdf_is_queued_and_returns_202(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('big.pdf', 8500, 'application/pdf');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/assignment', ['file' => $file])
            ->assertStatus(202);

        Queue::assertPushed(ProcessLargeUploadJob::class);
    }

    public function test_queued_record_status_endpoint_returns_pending(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('big.jpg')->size(3500);

        $recordId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads/course', ['file' => $file])
            ->assertStatus(202)
            ->json('data.record_id');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/uploads/status/{$recordId}")
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['status' => 'pending', 'record_id' => $recordId]]);
    }

    // ── Auth guards ───────────────────────────────────────────────────────────

    public function test_unauthenticated_upload_returns_401(): void
    {
        $this->postJson('/api/uploads/course', ['file' => UploadedFile::fake()->image('x.jpg')])
            ->assertStatus(401);
    }

    public function test_other_user_cannot_access_record_status(): void
    {
        $owner  = User::factory()->create();
        $other  = User::factory()->create();
        $record = UploadRecord::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/uploads/status/{$record->id}")
            ->assertStatus(403);
    }
}
