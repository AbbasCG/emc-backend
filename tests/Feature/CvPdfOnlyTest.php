<?php

namespace Tests\Feature;

use App\Http\Requests\StoreAmbassadorApplicationRequest;
use App\Http\Requests\StoreVolunteerHrProfileRequest;
use App\Models\ConsultantApplication;
use App\Models\User;
use App\Models\VolunteerRequest;
use App\Rules\PdfCvFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * New CV uploads are PDF only, on every EMC form that asks for a CV (volunteer, opportunity application,
 * consultant, ambassador, volunteer HR profile). The file's own bytes decide, never its name or the browser's
 * content type. Old CVs in other formats stay readable for the people who may read them.
 */
class CvPdfOnlyTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MakesCvFiles;

    private const MESSAGE = 'يُسمح برفع السيرة الذاتية بصيغة PDF فقط.';

    private function passes(UploadedFile $file): bool
    {
        return Validator::make(['cv_file' => $file], ['cv_file' => ['required', 'file', 'max:5120', new PdfCvFile]])->passes();
    }

    // ═══ The rule itself ═════════════════════════════════════════════════════

    public function test_a_real_pdf_passes(): void
    {
        $this->assertTrue($this->passes($this->pdfCv()));
        $this->assertTrue($this->passes($this->pdfCv('رسالة.PDF')), 'the extension check ignores case');
    }

    public function test_word_documents_are_rejected(): void
    {
        $this->assertFalse($this->passes($this->docxCv('cv.docx')));
        $this->assertFalse($this->passes($this->docxCv('cv.doc')));
    }

    public function test_images_are_rejected(): void
    {
        $this->assertFalse($this->passes($this->pngCv('cv.png')));
        $this->assertFalse($this->passes($this->pngCv('cv.jpg')));
    }

    public function test_a_file_renamed_to_pdf_is_rejected_by_its_bytes(): void
    {
        $this->assertFalse($this->passes($this->renamedExecutable('cv.pdf')));
        $this->assertFalse($this->passes($this->docxCv('cv.pdf')), 'a Word file renamed to .pdf');
        $this->assertFalse($this->passes($this->pngCv('cv.pdf')), 'an image renamed to .pdf');
    }

    public function test_a_real_pdf_under_another_extension_is_rejected_and_the_browser_claimed_type_is_ignored(): void
    {
        $pdf = $this->pdfCv();
        $body = file_get_contents($pdf->getRealPath());

        $this->assertFalse($this->passes(UploadedFile::fake()->createWithContent('cv.docx', $body)));
        // A PDF-typed claim over non-PDF bytes does not help.
        $this->assertFalse($this->passes(UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf')));
    }

    public function test_the_size_limit_is_unchanged(): void
    {
        $this->assertTrue($this->passes($this->pdfCv('cv.pdf', 5000)));
        $this->assertFalse($this->passes($this->pdfCv('cv.pdf', 5300)), 'over 5 MB');
    }

    public function test_the_message_is_the_arabic_instruction(): void
    {
        $v = Validator::make(['cv_file' => $this->docxCv('cv.docx')], ['cv_file' => [new PdfCvFile]]);

        $this->assertSame(self::MESSAGE, $v->errors()->first('cv_file'));
    }

    // ═══ Every form that asks for a CV ═══════════════════════════════════════

    private function volunteerPayload(array $over = []): array
    {
        return array_merge([
            'full_name' => 'Ahmed Al-Rashidi', 'email' => 'ahmed@example.com', 'phone' => '+9665012345678', 'country' => 'Saudi Arabia', 'city' => 'Riyadh', 'gender' => 'male',
            'desired_department' => 'Education', 'experience_level' => 'intermediate', 'availability' => 'weekends',
            'motivation' => 'I want to contribute to community education and make a difference.', 'agree_terms' => '1',
        ], $over);
    }

    public function test_the_general_volunteer_form_takes_a_pdf_and_stores_it_privately_under_a_server_made_name(): void
    {
        Storage::fake('private');

        $this->post('/api/volunteer-requests', $this->volunteerPayload(['cv_file' => $this->pdfCv('../../evil name.pdf')]), ['Accept' => 'application/json'])->assertCreated();

        $path = VolunteerRequest::firstOrFail()->cv_file_path;
        $this->assertMatchesRegularExpression('#^volunteer-cvs/cv_[0-9a-f-]{36}\.pdf$#', $path);
        Storage::disk('private')->assertExists($path);
    }

    public function test_the_general_volunteer_form_refuses_everything_else_and_keeps_the_cv_optional(): void
    {
        Storage::fake('private');

        foreach ([$this->docxCv('cv.docx'), $this->pngCv('cv.png'), $this->renamedExecutable('cv.pdf')] as $file) {
            $this->post('/api/volunteer-requests', $this->volunteerPayload(['cv_file' => $file]), ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors(['cv_file']);
        }
        $this->assertSame(0, VolunteerRequest::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_the_general_volunteer_form_still_accepts_no_cv_at_all(): void
    {
        Storage::fake('private');

        $this->post('/api/volunteer-requests', $this->volunteerPayload(), ['Accept' => 'application/json'])->assertCreated();

        $this->assertNull(VolunteerRequest::firstOrFail()->cv_file_path);
    }

    public function test_the_consultant_form_is_pdf_only(): void
    {
        Storage::fake('private');
        $payload = ['full_name' => 'Sara Consultant', 'email' => 'sara@example.com', 'specialty' => 'Data strategy', 'motivation' => 'I would like to advise EMC teams on data work.', 'agree_terms' => '1'];

        $this->post('/api/consultant-applications', $payload + ['cv_file' => $this->docxCv('cv.docx')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['cv_file']);
        $this->post('/api/consultant-applications', $payload + ['cv_file' => $this->renamedExecutable('cv.pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['cv_file']);
        $this->assertSame(0, ConsultantApplication::count());

        $this->post('/api/consultant-applications', $payload + ['cv_file' => $this->pdfCv()], ['Accept' => 'application/json'])->assertSuccessful();
        $this->assertMatchesRegularExpression('#\.pdf$#', ConsultantApplication::firstOrFail()->cv_file_path);
    }

    public function test_the_ambassador_and_volunteer_profile_forms_use_the_same_rule(): void
    {
        $ambassador = (new StoreAmbassadorApplicationRequest)->rules()['cv_file'];
        $this->assertContains('required', $ambassador, 'the ambassador CV stays required');
        $this->assertTrue(collect($ambassador)->contains(fn ($r) => $r instanceof PdfCvFile));

        $profile = (new StoreVolunteerHrProfileRequest)->rules()['cv_file'];
        $this->assertTrue(collect($profile)->contains(fn ($r) => $r instanceof PdfCvFile));
        $this->assertNotContains('mimes:pdf,doc,docx', $profile);
    }

    // ═══ Historical files ════════════════════════════════════════════════════

    public function test_an_old_word_cv_is_still_viewable_through_the_signed_link_and_not_without_it(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('volunteer-cvs/cv_old.docx', "PK\x03\x04old word file");
        $vr = VolunteerRequest::create($this->volunteerPayload(['agree_terms' => null]) + ['status' => 'pending', 'cv_file_path' => 'volunteer-cvs/cv_old.docx']);

        $signed = URL::temporarySignedRoute('admin.volunteer-requests.cv.view', now()->addHour(), ['volunteerRequest' => $vr->id]);
        $this->get($signed)->assertOk();
        $this->get("/api/admin/volunteer-requests/{$vr->id}/cv/view")->assertForbidden();
    }

    public function test_admin_lists_carry_a_cv_link_only_for_applications_that_have_one(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('volunteer-cvs/cv_old.docx', 'x');
        VolunteerRequest::create($this->volunteerPayload(['email' => 'old@example.com', 'agree_terms' => null]) + ['status' => 'pending', 'cv_file_path' => 'volunteer-cvs/cv_old.docx']);
        VolunteerRequest::create($this->volunteerPayload(['email' => 'none@example.com', 'agree_terms' => null]) + ['status' => 'pending']);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $json = $this->actingAs($admin)->getJson('/api/admin/volunteer-requests')->assertOk()->json();
        $rows = collect($json['data']['data'] ?? $json['data']);

        $this->assertNotNull($rows->firstWhere('email', 'old@example.com')['cv_view_url']);
        $this->assertNull($rows->firstWhere('email', 'none@example.com')['cv_view_url']);
    }
}
