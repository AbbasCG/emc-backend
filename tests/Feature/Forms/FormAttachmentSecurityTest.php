<?php

namespace Tests\Feature\Forms;

use App\Models\FormAnswer;
use App\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Files uploaded through a form question: what is accepted, where it is stored, and how it is served back.
 */
class FormAttachmentSecurityTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private $form;

    private int $fileQ;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('public');

        $this->form = $this->makeForm([], [['label' => 'مرفق', 'type' => 'file'], ['label' => 'ملاحظة', 'type' => 'text']]);
        $this->fileQ = $this->form->questions[0]->id;
    }

    private function upload(UploadedFile $file)
    {
        return $this->post("/api/forms/{$this->form->slug}/submit", ['answers' => [$this->fileQ => $file]], ['Accept' => 'application/json']);
    }

    // ── What is accepted ─────────────────────────────────────────────────────

    public function test_documents_images_and_text_files_are_accepted(): void
    {
        $this->upload($this->pdfUpload('a.pdf'))->assertCreated();
        $this->upload($this->pngUpload('b.png'))->assertCreated();
        $this->upload($this->docxUpload('c.docx'))->assertCreated();
        $this->upload(UploadedFile::fake()->createWithContent('d.txt', "ملاحظات\nنص عادي"))->assertCreated();
        $this->upload(UploadedFile::fake()->createWithContent('e.csv', "a,b\n1,2\n"))->assertCreated();

        $this->assertSame(5, FormAnswer::whereNotNull('file_path')->count());
    }

    public function test_scripts_pages_and_programs_are_rejected_whatever_they_are_called(): void
    {
        $rejected = [
            'a php file' => $this->phpUpload('shell.php'),
            'php disguised as a pdf' => $this->phpUpload('invoice.pdf'),
            'php hidden behind a double extension' => UploadedFile::fake()->createWithContent('photo.php.png', '<?php system($_GET["c"]); ?>'),
            'an html page' => UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>'),
            'an svg with a script' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'a windows program' => UploadedFile::fake()->createWithContent('setup.exe', "MZ\x90\x00".str_repeat("\0", 2048)),
            'a program named like a pdf' => UploadedFile::fake()->createWithContent('cv.pdf', "MZ\x90\x00".str_repeat("\0", 2048)),
            'text named like a pdf' => UploadedFile::fake()->createWithContent('cv.pdf', 'just some words'),
            'a non-zip named docx' => UploadedFile::fake()->createWithContent('letter.docx', str_repeat('A', 2048)),
            'a png that is really php' => UploadedFile::fake()->createWithContent('p.png', '<?php echo 1;'),
            'an archive' => UploadedFile::fake()->createWithContent('files.zip', "PK\x03\x04".str_repeat("\0", 2048)),
            'a file with no extension' => UploadedFile::fake()->createWithContent('noext', '%PDF-1.4 hi'),
        ];

        foreach ($rejected as $what => $file) {
            Cache::flush(); // twelve rejected attempts in one minute would otherwise trip the rate limiter, which is tested elsewhere
            $res = $this->upload($file);
            $this->assertSame(422, $res->getStatusCode(), "$what must be rejected");
            $this->assertArrayHasKey("answers.{$this->fileQ}", $res->json('errors'), $what);
        }
        $this->assertSame(0, FormSubmission::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_file_over_the_size_limit_is_rejected_with_a_clear_message(): void
    {
        $res = $this->upload($this->pdfUpload('big.pdf', 5121))->assertStatus(422);

        $this->assertStringContainsString('يتجاوز 5 ميغابايت', $res->json('errors')["answers.{$this->fileQ}"][0]);
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_a_required_file_question_needs_a_file_and_text_is_not_a_file(): void
    {
        $form = $this->makeForm([], [['label' => 'سيرة', 'type' => 'file', 'is_required' => true]]);
        $id = $form->questions->first()->id;

        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => []])->assertStatus(422)->assertJsonValidationErrors("answers.$id");
        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$id => 'C:\\fakepath\\cv.pdf']])->assertStatus(422)->assertJsonValidationErrors("answers.$id");
    }

    // ── Where it is stored ───────────────────────────────────────────────────

    public function test_a_file_goes_to_private_storage_under_a_server_made_name(): void
    {
        $res = $this->upload($this->pdfUpload('سيرة ذاتية "مهمة".pdf'))->assertCreated();

        $answer = FormAnswer::where('submission_id', $res->json('data.submission_id'))->whereNotNull('file_path')->firstOrFail();
        $this->assertMatchesRegularExpression('#^form-uploads/'.$this->form->id.'/[0-9a-f-]{36}\.pdf$#', $answer->file_path);
        Storage::disk('private')->assertExists($answer->file_path);
        Storage::disk('public')->assertMissing($answer->file_path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing is written where a public URL could reach it');
        $this->assertStringNotContainsString('سيرة', $answer->file_path, 'the client name is never part of the path');
        $this->assertSame('سيرة ذاتية _مهمة_.pdf', $answer->value, 'it survives only as sanitised display text');
    }

    public function test_the_response_never_exposes_the_storage_path(): void
    {
        $this->upload($this->pdfUpload('x.pdf'))->assertCreated();

        $res = $this->actingAs($this->makeUser('admin'), 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/submissions")->assertOk();

        $answer = collect($res->json('data.data.0.answers'))->firstWhere('question_id', $this->fileQ);
        $this->assertTrue($answer['has_file']);
        $this->assertSame('x.pdf', $answer['file_name']);
        $this->assertStringNotContainsString('form-uploads', $res->getContent());
        $this->assertArrayNotHasKey('file_path', $answer);
    }

    public function test_a_failed_submission_leaves_no_file_behind(): void
    {
        $textQ = $this->form->questions[1]->id;
        // The submission fails while its answers are being written, after the file has already been stored.
        FormAnswer::creating(function (FormAnswer $answer) use ($textQ) {
            if ((int) $answer->question_id === $textQ) {
                throw new \RuntimeException('simulated database failure');
            }
        });

        $res = $this->post("/api/forms/{$this->form->slug}/submit", ['answers' => [$this->fileQ => $this->pdfUpload('lost.pdf'), $textQ => 'x']], ['Accept' => 'application/json']);

        $this->assertSame(500, $res->getStatusCode());
        $this->assertSame(0, FormSubmission::count(), 'the whole submission rolled back');
        $this->assertSame([], Storage::disk('private')->allFiles(), 'and its file was removed');
        FormAnswer::flushEventListeners();
    }

    // ── How it is served ─────────────────────────────────────────────────────

    private function storedAnswer(): array
    {
        $res = $this->upload($this->pdfUpload('تقرير.pdf'))->assertCreated();
        $submission = FormSubmission::findOrFail($res->json('data.submission_id'));
        $answer = FormAnswer::where('submission_id', $submission->id)->whereNotNull('file_path')->firstOrFail();

        return [$submission, $answer, "/api/operations/forms/{$this->form->id}/submissions/{$submission->id}/answers/{$answer->id}/file"];
    }

    public function test_the_download_is_an_attachment_that_a_browser_will_not_render(): void
    {
        [, , $url] = $this->storedAnswer();

        $res = $this->actingAs($this->makeUser('admin'), 'sanctum')->get($url)->assertOk();

        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', $res->streamedContent());
    }

    public function test_a_missing_file_is_a_clean_404(): void
    {
        [, $answer, $url] = $this->storedAnswer();
        Storage::disk('private')->delete($answer->file_path);

        $this->actingAs($this->makeUser('admin'), 'sanctum')->getJson($url)->assertNotFound();
    }

    public function test_an_answer_without_a_file_has_nothing_to_download(): void
    {
        [$submission] = $this->storedAnswer();
        $textAnswer = FormAnswer::where('submission_id', $submission->id)->whereNull('file_path')->firstOrFail();

        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->getJson("/api/operations/forms/{$this->form->id}/submissions/{$submission->id}/answers/{$textAnswer->id}/file")->assertNotFound();
    }

    public function test_a_download_cannot_be_forced_to_read_another_path(): void
    {
        [$submission, $answer] = $this->storedAnswer();
        Storage::disk('private')->put('secrets/other.txt', 'top secret');
        $answer->update(['file_path' => 'form-uploads/../secrets/other.txt']);

        $res = $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->getJson("/api/operations/forms/{$this->form->id}/submissions/{$submission->id}/answers/{$answer->id}/file");

        $res->assertNotFound();
    }

    // ── Files from before this change ────────────────────────────────────────

    public function test_a_legacy_public_disk_file_is_still_served_through_the_secure_endpoint(): void
    {
        $submission = FormSubmission::create(['form_id' => $this->form->id, 'status' => 'new', 'submitted_at' => now()]);
        Storage::disk('public')->put('form-uploads/OldRandomName1234', '%PDF-1.4 legacy');
        $answer = FormAnswer::create(['submission_id' => $submission->id, 'question_id' => $this->fileQ, 'file_path' => 'form-uploads/OldRandomName1234', 'value' => null]);

        $res = $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->get("/api/operations/forms/{$this->form->id}/submissions/{$submission->id}/answers/{$answer->id}/file")->assertOk();

        $this->assertSame('%PDF-1.4 legacy', $res->streamedContent());
        $this->assertSame('application/octet-stream', $res->headers->get('Content-Type'), 'an old file with no known extension is never given a renderable type');
    }

    public function test_the_copy_command_moves_nothing_unless_told_to_and_never_deletes(): void
    {
        $submission = FormSubmission::create(['form_id' => $this->form->id, 'status' => 'new', 'submitted_at' => now()]);
        Storage::disk('public')->put('form-uploads/Legacy1', 'one');
        Storage::disk('public')->put('form-uploads/Legacy2', 'two');
        foreach (['Legacy1', 'Legacy2'] as $name) {
            FormAnswer::create(['submission_id' => $submission->id, 'question_id' => $this->fileQ, 'file_path' => "form-uploads/$name"]);
        }
        FormAnswer::create(['submission_id' => $submission->id, 'question_id' => $this->fileQ, 'file_path' => 'form-uploads/Gone']);

        $this->artisan('forms:copy-legacy-uploads')->expectsOutputToContain('would copy: 2')->assertSuccessful();
        $this->assertSame([], Storage::disk('private')->allFiles(), 'dry run copies nothing');

        $this->artisan('forms:copy-legacy-uploads', ['--apply' => true])->expectsOutputToContain('copied: 2; already private: 0; missing: 1')->assertSuccessful();
        Storage::disk('private')->assertExists('form-uploads/Legacy1');
        $this->assertSame('two', Storage::disk('private')->get('form-uploads/Legacy2'));
        Storage::disk('public')->assertExists('form-uploads/Legacy1');
        Storage::disk('public')->assertExists('form-uploads/Legacy2');

        $this->artisan('forms:copy-legacy-uploads', ['--apply' => true])->expectsOutputToContain('copied: 0; already private: 2; missing: 1')->assertSuccessful();
        $this->assertSame(3, FormAnswer::whereNotNull('file_path')->count(), 'no database row was touched');
    }

    public function test_the_copy_command_never_overwrites_a_private_file_and_skips_paths_that_are_not_form_uploads(): void
    {
        $submission = FormSubmission::create(['form_id' => $this->form->id, 'status' => 'new', 'submitted_at' => now()]);
        Storage::disk('public')->put('form-uploads/Same', 'public version');
        Storage::disk('private')->put('form-uploads/Same', 'private version that must survive');
        Storage::disk('private')->put('secrets/other.txt', 'not a form upload');
        FormAnswer::create(['submission_id' => $submission->id, 'question_id' => $this->fileQ, 'file_path' => 'form-uploads/Same']);
        foreach (['form-uploads/../secrets/other.txt', '/etc/passwd', 'other-dir/x.pdf', 'form-uploads//x'] as $bad) {
            FormAnswer::create(['submission_id' => $submission->id, 'question_id' => $this->fileQ, 'file_path' => $bad]);
        }
        $before = FormAnswer::orderBy('id')->pluck('file_path', 'id')->all();

        $this->artisan('forms:copy-legacy-uploads', ['--apply' => true])->expectsOutputToContain('copied: 0; already private: 1; missing: 0; skipped: 4; failed: 0')->assertSuccessful();

        $this->assertSame('private version that must survive', Storage::disk('private')->get('form-uploads/Same'));
        $this->assertSame('public version', Storage::disk('public')->get('form-uploads/Same'));
        $this->assertSame('not a form upload', Storage::disk('private')->get('secrets/other.txt'));
        $this->assertSame($before, FormAnswer::orderBy('id')->pluck('file_path', 'id')->all());
    }

    /**
     * Found when a virus scanner on a developer machine locked a temp file holding a web-shell payload: the upload could not be
     * opened, finfo warned, and the visitor got a 500. An upload that cannot be read is simply refused, in the form's own words.
     */
    public function test_an_upload_that_cannot_be_read_is_refused_not_answered_with_a_server_error(): void
    {
        $unreadable = \Mockery::mock(UploadedFile::class);
        $unreadable->shouldReceive('isValid')->andReturn(true);
        $unreadable->shouldReceive('getClientOriginalExtension')->andReturn('png');
        $unreadable->shouldReceive('getRealPath')->andReturn(storage_path('framework/testing/this-file-does-not-exist.png'));

        foreach ([new \App\Rules\FormAttachmentFile, new \App\Rules\FormAttachmentFile(\App\Rules\FormAttachmentFile::IMAGES)] as $rule) {
            $messages = [];
            $rule->validate('answers.1', $unreadable, function (string $message) use (&$messages) { $messages[] = $message; });
            $this->assertCount(1, $messages, 'refused exactly once, without an exception');
        }

        $nothing = \Mockery::mock(UploadedFile::class);
        $nothing->shouldReceive('isValid')->andReturn(true);
        $nothing->shouldReceive('getClientOriginalExtension')->andReturn('pdf');
        $nothing->shouldReceive('getRealPath')->andReturn(false);
        $messages = [];
        (new \App\Rules\FormAttachmentFile)->validate('answers.1', $nothing, function (string $message) use (&$messages) { $messages[] = $message; });
        $this->assertCount(1, $messages);
    }

    public function test_a_stored_path_outside_the_form_upload_directory_is_never_read_or_served(): void
    {
        foreach (['form-uploads/../secrets/other.txt', '/etc/passwd', 'form-uploads//x', 'other/x.pdf', 'form-uploads/a b.pdf'] as $path) {
            $this->assertFalse(\App\Services\Forms\FormAttachmentService::isSafePath($path), $path);
        }
        $this->assertTrue(\App\Services\Forms\FormAttachmentService::isSafePath('form-uploads/12/5f1b4b9c-36af-4cac-8e5f-46884be6a67c.pdf'));
        $this->assertTrue(\App\Services\Forms\FormAttachmentService::isSafePath('form-uploads/BPytolPowbgIKNTCX9L9YrZfPGTYZ69Ke7dkR5m3'), 'the extensionless names the old code wrote');
    }
}
