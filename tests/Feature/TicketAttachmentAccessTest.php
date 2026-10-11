<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TICKET WORKSPACE — attachment retrieval contract.
 *
 * The assignee workspace previews attachments through the authenticated
 * download endpoint. These tests pin what that endpoint and the list payload
 * guarantee: who may read a file, how each type is served, and that no storage
 * path ever reaches or escapes the private disk.
 */
class TicketAttachmentAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

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

    private function makeTicket(User $owner, ?User $assignee = null, string $status = 'IN_PROGRESS'): Ticket
    {
        return Ticket::create([
            'ticket_number' => 'EMC-TCK-2026-'.str_pad((string) (Ticket::count() + 1), 3, '0', STR_PAD_LEFT),
            'title' => 'تذكرة', 'description' => 'وصف',
            'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $this->department->id,
            'created_by_id' => $owner->id, 'created_by_name' => $owner->name,
            'assigned_to_id' => $assignee?->id,
            'status' => $status,
        ]);
    }

    private function attach(
        Ticket $ticket,
        string $name,
        string $bytes,
        string $fileType = 'IMAGE',
        ?string $mime = null,
        ?string $storedPath = null,
    ): TicketAttachment {
        $path = $storedPath ?? 'tickets/attachments/'.md5($name.$bytes).'.bin';

        if ($storedPath === null) {
            Storage::disk('private')->put($path, $bytes);
        }

        return TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'file_url' => $path,
            'file_name' => $name,
            'file_type' => $fileType,
            'mime_type' => $mime,
            'file_size' => strlen($bytes),
            'attachment_context' => 'INITIAL_SUBMISSION',
            'uploaded_by' => $ticket->created_by_id,
        ]);
    }

    private function url(TicketAttachment $attachment): string
    {
        return "/api/v1/tickets/{$attachment->ticket_id}/attachments/{$attachment->id}/download";
    }

    // ── Authorization ────────────────────────────────────────────────────────

    public function test_the_assigned_staff_member_can_retrieve_a_ticket_image(): void
    {
        $owner = $this->makeUser();
        $assignee = $this->makeUser('employee');
        $attachment = $this->attach($this->makeTicket($owner, $assignee), 'shot.png', base64_decode(self::PNG), 'IMAGE', 'image/png');

        Sanctum::actingAs($assignee);

        $this->get($this->url($attachment))->assertOk();
    }

    public function test_a_staff_member_assigned_to_a_different_ticket_cannot_retrieve_it(): void
    {
        $owner = $this->makeUser();
        $assignee = $this->makeUser('employee');
        $other = $this->makeUser('employee');
        $attachment = $this->attach($this->makeTicket($owner, $assignee), 'shot.png', base64_decode(self::PNG));

        Sanctum::actingAs($other);

        $this->getJson($this->url($attachment))->assertForbidden();
    }

    public function test_guessing_sequential_attachment_ids_does_not_cross_tickets(): void
    {
        $owner = $this->makeUser();
        $mine = $this->attach($this->makeTicket($owner), 'mine.png', base64_decode(self::PNG));

        $victim = $this->makeUser();
        $theirs = $this->attach($this->makeTicket($victim), 'theirs.png', base64_decode(self::PNG).'x');

        Sanctum::actingAs($owner);

        // Own ticket + someone else's attachment id: refused as not-found...
        $this->getJson("/api/v1/tickets/{$mine->ticket_id}/attachments/{$theirs->id}/download")->assertNotFound();
        // ...and the victim's ticket id is refused outright.
        $this->getJson($this->url($theirs))->assertForbidden();
    }

    // ── Serving behaviour ────────────────────────────────────────────────────

    public function test_an_image_is_served_inline_with_its_real_content_type_and_safe_headers(): void
    {
        $owner = $this->makeUser();
        $attachment = $this->attach($this->makeTicket($owner), 'shot.png', base64_decode(self::PNG), 'IMAGE', 'image/png');

        Sanctum::actingAs($owner);

        $response = $this->get($this->url($attachment))->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_a_pdf_downloads_with_the_pdf_content_type(): void
    {
        $owner = $this->makeUser();
        $attachment = $this->attach($this->makeTicket($owner), 'report.pdf', self::PDF, 'DOCUMENT', 'application/pdf');

        Sanctum::actingAs($owner);

        $response = $this->get($this->url($attachment))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_active_content_is_forced_to_download_never_rendered_inline(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);

        $html = $this->attach($ticket, 'page.html', '<html><body><script>alert(1)</script></body></html>', 'DOCUMENT');
        $svg = $this->attach($ticket, 'logo.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>', 'IMAGE');

        Sanctum::actingAs($owner);

        foreach ([$html, $svg] as $attachment) {
            $disposition = $this->get($this->url($attachment))->assertOk()->headers->get('Content-Disposition');
            $this->assertStringStartsWith('attachment;', $disposition, "{$attachment->file_name} must not render inline.");
        }
    }

    public function test_the_download_filename_cannot_inject_headers(): void
    {
        $owner = $this->makeUser();
        $attachment = $this->attach($this->makeTicket($owner), "evil\"\r\nX-Injected: 1.png", base64_decode(self::PNG));

        Sanctum::actingAs($owner);

        $response = $this->get($this->url($attachment))->assertOk();

        $this->assertNull($response->headers->get('X-Injected'));
        $this->assertStringNotContainsString("\n", $response->headers->get('Content-Disposition'));
    }

    // ── Path traversal ───────────────────────────────────────────────────────

    public function test_a_traversal_style_attachment_id_resolves_to_nothing(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/tickets/{$ticket->id}/attachments/..%2F..%2F.env/download")
            ->assertStatus(404);
    }

    public function test_a_tampered_stored_path_can_never_escape_the_private_disk(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);

        Storage::disk('private')->put('secret.txt', 'top-secret');

        foreach (['tickets/../secret.txt', '../secret.txt', '/etc/passwd'] as $evilPath) {
            $attachment = $this->attach($ticket, 'x.png', 'x', 'IMAGE', 'image/png', $evilPath);

            Sanctum::actingAs($owner);

            $response = $this->getJson($this->url($attachment));

            $response->assertNotFound();
            $this->assertStringNotContainsString('top-secret', $response->getContent());
        }
    }

    // ── Payload contract the workspace depends on ────────────────────────────

    public function test_the_assignee_list_exposes_everything_the_preview_needs_and_no_storage_path(): void
    {
        $owner = $this->makeUser();
        $assignee = $this->makeUser('employee');
        $ticket = $this->makeTicket($owner, $assignee, 'ASSIGNED');
        $this->attach($ticket, 'shot.png', base64_decode(self::PNG), 'IMAGE', 'image/png');

        Sanctum::actingAs($assignee);

        $response = $this->getJson('/api/v1/tickets?assigned_to_id='.$assignee->id)->assertOk();

        $row = collect($response->json('data.data'))->firstWhere('id', $ticket->id);
        $this->assertNotNull($row, 'Assignee must see their assigned ticket.');

        $attachment = $row['attachments'][0];
        $this->assertTrue($attachment['is_image']);
        $this->assertSame('image/png', $attachment['mime_type']);
        $this->assertGreaterThan(0, $attachment['file_size']);
        $this->assertSame('shot.png', $attachment['file_name']);
        $this->assertNotEmpty($attachment['created_at']);
        $this->assertStringContainsString("/tickets/{$ticket->id}/attachments/{$attachment['id']}/download", $attachment['preview_url']);
        $this->assertArrayNotHasKey('file_url', $attachment);
        $this->assertStringNotContainsString('tickets/attachments/', json_encode($attachment));

        // Server-computed capabilities travel with the same payload.
        $this->assertTrue($row['capabilities']['accept_task']);
        $this->assertFalse($row['capabilities']['complete_task']);
    }

    public function test_an_unrelated_users_list_never_contains_someone_elses_attachments(): void
    {
        $owner = $this->makeUser();
        $this->attach($this->makeTicket($owner), 'private.png', base64_decode(self::PNG));

        Sanctum::actingAs($this->makeUser('partner'));

        $response = $this->getJson('/api/v1/tickets')->assertOk();

        $this->assertSame([], $response->json('data.data'));
        $this->assertStringNotContainsString('private.png', $response->getContent());
    }
}
