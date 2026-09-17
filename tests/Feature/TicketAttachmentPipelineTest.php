<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * EMC TICKETS — ATTACHMENT & IMAGE PIPELINE.
 *
 * Covers the whole flow: validation policy, private storage, stored metadata,
 * image optimisation, and authenticated + authorised retrieval of both new and
 * historical attachments.
 */
class TicketAttachmentPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->department = Department::create([
            'name' => 'tech', 'name_ar' => 'التقنية', 'slug' => 'tech-support',
            'status' => 'active', 'is_active' => true,
        ]);
    }

    private function makeUser(string $role = 'student'): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /** @param array<int, UploadedFile> $files */
    private function submit(array $files = [], ?User $as = null): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($as ?? $this->makeUser());

        return $this->postJson('/api/v1/tickets', [
            'title' => 'عنوان التذكرة',
            'description' => 'وصف المشكلة بالتفصيل',
            'ticket_category' => 'OLD_ISSUE',
            'priority' => 'MEDIUM',
            'department_id' => $this->department->id,
            'created_by_name' => 'مستخدم',
            'attachments' => $files,
        ]);
    }

    /** A real, decodable image — not a fake string, so processing runs for real. */
    private function realImage(string $name, string $ext, int $w = 40, int $h = 30): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h)->mimeType(match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        });
    }

    // ── 1/2/3/4. Creation succeeds ───────────────────────────────────────────

    public function test_a_ticket_without_attachments_is_created(): void
    {
        $this->submit()->assertCreated()->assertJsonPath('success', true);

        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(0, TicketAttachment::query()->count());
    }

    public function test_a_ticket_with_a_jpeg_is_created_and_stored_privately(): void
    {
        $response = $this->submit([$this->realImage('shot.jpg', 'jpg')]);

        $response->assertCreated()->assertJsonPath('success', true);

        $this->assertSame(1, TicketAttachment::query()->count());

        $attachment = TicketAttachment::query()->firstOrFail();
        Storage::disk('private')->assertExists($attachment->file_url);
        $this->assertStringStartsWith('tickets/attachments/', $attachment->file_url);
    }

    public function test_a_ticket_with_a_png_is_created(): void
    {
        $this->submit([$this->realImage('shot.png', 'png')])->assertCreated();

        $this->assertSame('IMAGE', TicketAttachment::query()->firstOrFail()->file_type);
    }

    public function test_a_ticket_with_a_webp_is_created(): void
    {
        $this->submit([$this->realImage('shot.webp', 'webp')])->assertCreated();

        $this->assertSame(1, TicketAttachment::query()->count());
    }

    public function test_a_ticket_with_a_pdf_is_created_and_not_treated_as_an_image(): void
    {
        $pdf = UploadedFile::fake()->create('report.pdf', 20, 'application/pdf');

        $this->submit([$pdf])->assertCreated();

        $attachment = TicketAttachment::query()->firstOrFail();
        $this->assertSame('DOCUMENT', $attachment->file_type);
        Storage::disk('private')->assertExists($attachment->file_url);
    }

    // ── 6/7/8/9/10. Rejection policy ─────────────────────────────────────────

    public function test_video_uploads_are_rejected(): void
    {
        foreach ([['clip.mp4', 'video/mp4'], ['clip.mov', 'video/quicktime'], ['clip.avi', 'video/x-msvideo'], ['clip.webm', 'video/webm']] as [$name, $mime]) {
            $response = $this->submit([UploadedFile::fake()->create($name, 100, $mime)]);

            $response->assertStatus(422);
            $this->assertSame(0, TicketAttachment::query()->count(), "[{$name}] must not be stored.");
            $this->assertSame(0, Ticket::query()->count(), "[{$name}] must not create a ticket.");
        }
    }

    public function test_svg_uploads_are_rejected(): void
    {
        $svg = UploadedFile::fake()->createWithContent(
            'x.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->submit([$svg])->assertStatus(422);

        $this->assertSame(0, TicketAttachment::query()->count());
    }

    public function test_arbitrary_binary_uploads_are_rejected(): void
    {
        foreach ([['evil.php', 'application/x-php'], ['evil.exe', 'application/octet-stream'], ['evil.sh', 'text/x-shellscript']] as [$name, $mime]) {
            $this->submit([UploadedFile::fake()->create($name, 10, $mime)])->assertStatus(422);
        }

        $this->assertSame(0, TicketAttachment::query()->count());
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        $huge = UploadedFile::fake()->create('big.jpg', 60 * 1024, 'image/jpeg');

        $this->submit([$huge])->assertStatus(422);

        $this->assertSame(0, TicketAttachment::query()->count());
    }

    // ── 12. Metadata ─────────────────────────────────────────────────────────

    public function test_attachment_metadata_is_recorded(): void
    {
        $this->submit([$this->realImage('shot.jpg', 'jpg')])->assertCreated();

        $attachment = TicketAttachment::query()->firstOrFail();

        $this->assertSame('IMAGE', $attachment->file_type);
        $this->assertNotEmpty($attachment->file_name);
        $this->assertNotNull($attachment->uploaded_by);
        $this->assertSame('INITIAL_SUBMISSION', $attachment->attachment_context);

        // The physical name must be server-generated, never the client's.
        $this->assertStringNotContainsString('shot.jpg', $attachment->file_url);
    }

    public function test_the_stored_path_is_never_taken_from_the_client_filename(): void
    {
        $evil = UploadedFile::fake()->image('../../evil.jpg', 20, 20)->mimeType('image/jpeg');

        $this->submit([$evil])->assertCreated();

        $path = TicketAttachment::query()->firstOrFail()->file_url;
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringStartsWith('tickets/attachments/', $path);
    }

    // ── 15/16/17/18/19. Retrieval ────────────────────────────────────────────

    private function seedTicketWithAttachment(User $owner, string $content = 'file-bytes'): TicketAttachment
    {
        $ticket = Ticket::create([
            'ticket_number' => 'EMC-TCK-2026-001',
            'title' => 't', 'description' => 'd',
            'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $this->department->id,
            'created_by_id' => $owner->id, 'created_by_name' => $owner->name,
            'status' => 'PENDING_APPROVAL',
        ]);

        Storage::disk('private')->put('tickets/attachments/legacy.png', $content);

        return TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'file_url' => 'tickets/attachments/legacy.png',
            'file_name' => 'legacy.png',
            'file_type' => 'IMAGE',
            'attachment_context' => 'INITIAL_SUBMISSION',
            'uploaded_by' => $owner->id,
        ]);
    }

    public function test_the_ticket_owner_can_retrieve_their_attachment(): void
    {
        $owner = $this->makeUser();
        $attachment = $this->seedTicketWithAttachment($owner);

        Sanctum::actingAs($owner);

        $this->get("/api/v1/tickets/{$attachment->ticket_id}/attachments/{$attachment->id}/download")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename="legacy.png"');
    }

    public function test_an_unauthenticated_attachment_request_is_refused(): void
    {
        $attachment = $this->seedTicketWithAttachment($this->makeUser());

        $this->getJson("/api/v1/tickets/{$attachment->ticket_id}/attachments/{$attachment->id}/download")
            ->assertUnauthorized();
    }

    public function test_an_unrelated_user_cannot_retrieve_the_attachment(): void
    {
        $attachment = $this->seedTicketWithAttachment($this->makeUser());

        Sanctum::actingAs($this->makeUser('partner'));

        $this->getJson("/api/v1/tickets/{$attachment->ticket_id}/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }

    public function test_an_attachment_cannot_be_read_through_a_different_ticket(): void
    {
        $owner = $this->makeUser();
        $attachment = $this->seedTicketWithAttachment($owner);

        $otherTicket = Ticket::create([
            'ticket_number' => 'EMC-TCK-2026-002',
            'title' => 't2', 'description' => 'd2',
            'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $this->department->id,
            'created_by_id' => $owner->id, 'created_by_name' => $owner->name,
            'status' => 'PENDING_APPROVAL',
        ]);

        Sanctum::actingAs($owner);

        // Same owner, so authorization passes - the attachment/ticket mismatch
        // itself must still refuse.
        $this->getJson("/api/v1/tickets/{$otherTicket->id}/attachments/{$attachment->id}/download")
            ->assertNotFound();
    }

    // ── 20. Historical records ───────────────────────────────────────────────

    public function test_a_historical_attachment_without_stored_mime_is_still_previewable(): void
    {
        $owner = $this->makeUser();

        // A real PNG byte header, as an old row would have on disk.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $attachment = $this->seedTicketWithAttachment($owner, $png);

        Sanctum::actingAs($owner);

        $response = $this->get("/api/v1/tickets/{$attachment->ticket_id}/attachments/{$attachment->id}/download");

        $response->assertOk();
        // MIME is derived from the file on disk, so no stored column is needed.
        $this->assertStringStartsWith('image/', $response->headers->get('Content-Type'));
    }

    // ── 21. No orphans ───────────────────────────────────────────────────────

    public function test_a_rejected_submission_leaves_no_file_and_no_row(): void
    {
        $this->submit([UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4')])->assertStatus(422);

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, TicketAttachment::query()->count());
        $this->assertEmpty(Storage::disk('private')->allFiles('tickets/attachments'));
    }

    // ── ROOT CAUSE REGRESSION ────────────────────────────────────────────────

    /**
     * The production 500. `count() + 1` is not a sequence: hard-deleting a
     * ticket lowers the count so the next number collides with the UNIQUE index
     * on ticket_number, the QueryException was swallowed, and the user saw the
     * generic save error. No attachment is involved.
     */
    public function test_a_hard_deleted_ticket_does_not_break_the_next_submission(): void
    {
        $owner = $this->makeUser();
        $prefix = 'EMC-TCK-'.date('Y').'-';

        foreach ([$prefix.'001', $prefix.'002'] as $number) {
            Ticket::create([
                'ticket_number' => $number, 'title' => 't', 'description' => 'd',
                'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
                'department_id' => $this->department->id,
                'created_by_id' => $owner->id, 'created_by_name' => 'x',
                'status' => 'PENDING_APPROVAL',
            ]);
        }

        Ticket::where('ticket_number', $prefix.'001')->forceDelete();

        $this->submit()
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ticket_number', $prefix.'003');
    }

    public function test_ticket_numbers_never_repeat_across_many_submissions(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->submit()->assertCreated();
        }

        $numbers = Ticket::query()->pluck('ticket_number')->all();

        $this->assertCount(5, $numbers);
        $this->assertCount(5, array_unique($numbers), 'Ticket numbers must be unique.');
    }

    // ── 13/14. Image optimisation ────────────────────────────────────────────

    public function test_a_large_image_is_scaled_down_and_stays_decodable(): void
    {
        $this->submit([$this->realImage('huge.jpg', 'jpg', 4000, 3000)])->assertCreated();

        $attachment = TicketAttachment::query()->firstOrFail();
        $bytes = Storage::disk('private')->get($attachment->file_url);

        $info = getimagesizefromstring($bytes);
        $this->assertNotFalse($info, 'The stored image must still decode.');
        $this->assertLessThanOrEqual(2560, $info[0]);
        $this->assertLessThanOrEqual(2560, $info[1]);
    }

    public function test_a_small_image_is_never_upscaled(): void
    {
        $this->submit([$this->realImage('small.jpg', 'jpg', 120, 90)])->assertCreated();

        $bytes = Storage::disk('private')->get(TicketAttachment::query()->firstOrFail()->file_url);
        $info = getimagesizefromstring($bytes);

        $this->assertSame(120, $info[0]);
        $this->assertSame(90, $info[1]);
    }

    public function test_a_png_stays_a_png_so_transparency_survives(): void
    {
        $this->submit([$this->realImage('ui.png', 'png', 200, 150)])->assertCreated();

        $attachment = TicketAttachment::query()->firstOrFail();

        $this->assertStringEndsWith('.png', $attachment->file_url);
        $this->assertSame('image/png', $attachment->mime_type);

        $info = getimagesizefromstring(Storage::disk('private')->get($attachment->file_url));
        $this->assertSame(IMAGETYPE_PNG, $info[2]);
    }

    public function test_a_pdf_is_stored_byte_for_byte_and_never_image_processed(): void
    {
        $content = '%PDF-1.4 fake body for byte comparison';
        $pdf = UploadedFile::fake()->createWithContent('report.pdf', $content);

        $this->submit([$pdf])->assertCreated();

        $attachment = TicketAttachment::query()->firstOrFail();

        $this->assertSame('DOCUMENT', $attachment->file_type);
        $this->assertSame($content, Storage::disk('private')->get($attachment->file_url));
    }

    public function test_recorded_metadata_includes_mime_and_size(): void
    {
        $this->submit([$this->realImage('shot.jpg', 'jpg')])->assertCreated();

        $attachment = TicketAttachment::query()->firstOrFail();

        $this->assertSame('image/jpeg', $attachment->mime_type);
        $this->assertGreaterThan(0, $attachment->file_size);
        $this->assertSame(
            Storage::disk('private')->size($attachment->file_url),
            $attachment->file_size,
            'Recorded size must match the stored file.',
        );
        $this->assertTrue($attachment->is_image);
    }

    public function test_a_historical_row_without_mime_is_still_flagged_as_an_image(): void
    {
        $attachment = $this->seedTicketWithAttachment($this->makeUser());

        $this->assertNull($attachment->mime_type);
        $this->assertTrue($attachment->fresh()->is_image, 'Falls back to file_type for old rows.');
    }

    public function test_the_api_never_exposes_the_raw_storage_path(): void
    {
        $response = $this->submit([$this->realImage('shot.jpg', 'jpg')]);

        $response->assertCreated();
        $response->assertJsonMissingPath('data.attachments.0.file_url');

        $attachment = $response->json('data.attachments.0');
        $this->assertArrayHasKey('preview_url', $attachment);
        $this->assertArrayHasKey('is_image', $attachment);
        $this->assertStringNotContainsString('tickets/attachments', json_encode($attachment));
    }
}
