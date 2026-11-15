<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionFile;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Registration;
use App\Models\User;
use App\Services\AssignmentUploads\PhpUploadLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsUploadFixtures;
use Tests\TestCase;

/**
 * POST /api/student/assignments/{assignment}/submit with real multipart uploads, plus the authorized per-file download.
 */
class AssignmentSubmissionUploadsTest extends TestCase
{
    use BuildsUploadFixtures;
    use RefreshDatabase;

    private User $student;

    private User $teacher;

    private Instructor $profile;

    private Course $course;

    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->teacher = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $this->profile = Instructor::create(['user_id' => $this->teacher->id, 'name' => $this->teacher->name, 'title' => 'Instructor']);
        $this->course = Course::factory()->create(['title' => 'English', 'status' => 'published', 'instructor_id' => $this->profile->id]);
        $this->student = $this->enrolledStudent();
        $this->assignment = $this->assignmentFor($this->course);
    }

    private function enrolledStudent(?Course $course = null): User
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        Registration::create([
            'user_id' => $student->id, 'course_id' => ($course ?? $this->course)->id,
            'full_name' => $student->name, 'email' => $student->email, 'phone' => '0500000000', 'status' => 'registered',
        ]);

        return $student;
    }

    private function assignmentFor(Course $course, bool $resubmission = true): Assignment
    {
        return Assignment::create([
            'course_id' => $course->id, 'title' => 'HW', 'status' => 'published',
            'max_score' => 100, 'resubmission_allowed' => $resubmission,
        ]);
    }

    private function submit(array $data, ?User $as = null, ?Assignment $assignment = null)
    {
        $assignment ??= $this->assignment;

        return $this->actingAs($as ?? $this->student, 'sanctum')
            ->post("/api/student/assignments/{$assignment->id}/submit", $data, ['Accept' => 'application/json']);
    }

    private function storedFileCount(): int
    {
        return count(Storage::disk('private')->allFiles('assignment-submissions'));
    }

    private function padded(string $head, int $bytes): string
    {
        return $head.str_repeat('0', max(0, $bytes - strlen($head)));
    }

    // ── accepted types ─────────────────────────────────────────────────────────────────────────────────────

    public static function acceptedTypes(): array
    {
        return [
            'jpg'  => ['p.jpg', 'jpgBytes', 'image/jpeg'],
            'png'  => ['p.png', 'pngBytes', 'image/png'],
            'webp' => ['p.webp', 'webpBytes', 'image/webp'],
            'pdf'  => ['p.pdf', 'pdfBytes', 'application/pdf'],
            'docx' => ['p.docx', fn ($t) => $t->ooxmlBytes('word'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xlsx' => ['p.xlsx', fn ($t) => $t->ooxmlBytes('xl'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'csv'  => ['p.csv', fn () => "a,b\n1,2\n", 'text/csv'],
            'txt'  => ['p.txt', fn () => "plain answer\n", 'text/plain'],
            'zip'  => ['p.zip', fn ($t) => $t->zipBytes(), 'application/zip'],
            'rar'  => ['p.rar', fn ($t) => $t->rarBytes(), 'application/vnd.rar'],
            '7z'   => ['p.7z', fn ($t) => $t->sevenZipBytes(), 'application/x-7z-compressed'],
            'pptx' => ['p.pptx', fn ($t) => $t->ooxmlBytes('ppt'), 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedTypes')]
    public function test_each_allowed_type_uploads_and_is_stored_privately(string $name, string|\Closure $bytes, string $mime): void
    {
        $content = $bytes instanceof \Closure ? $bytes($this) : $this->{$bytes}();

        $response = $this->submit(['files' => [$this->upload($name, $content)]])->assertStatus(201);

        $response->assertJsonPath('data.attachments.0.name', $name)
            ->assertJsonPath('data.attachments.0.mime', $mime)
            ->assertJsonPath('data.attachments.0.size', strlen($content));

        $row = AssignmentSubmissionFile::firstOrFail();
        $this->assertSame(hash('sha256', $content), $row->sha256);
        Storage::disk('private')->assertExists($row->stored_path);
        // Stored under a server-generated name, never the client's.
        $this->assertMatchesRegularExpression('#^assignment-submissions/\d+/\d+/[0-9a-f-]{36}\.[a-z0-9]+$#', $row->stored_path);
        $this->assertStringNotContainsString('p.', basename($row->stored_path));
        $this->assertSame(Storage::disk('private')->get($row->stored_path), $content);

        // No storage path and no public URL anywhere in the payload.
        $body = $response->getContent();
        $this->assertStringNotContainsString('stored_path', $body);
        $this->assertStringNotContainsString($row->stored_path, $body);
        $this->assertStringNotContainsString('/storage/', $body);
        $this->assertStringContainsString("/api/submissions/{$row->submission_id}/files/{$row->id}/download", $response->json('data.attachments.0.download_url'));
        $this->assertSame([], Storage::disk('public')->allFiles('assignment-submissions'));
    }

    public function test_multiple_files_and_arabic_names(): void
    {
        $response = $this->submit([
            'text_answer' => 'إجابتي',
            'files' => [
                $this->upload('تقرير الواجب النهائي.pdf', $this->pdfBytes()),
                $this->upload('صورة.png', $this->pngBytes()),
                $this->upload('بيانات.csv', "a,b\n"),
            ],
        ])->assertStatus(201);

        $this->assertSame(['تقرير الواجب النهائي.pdf', 'صورة.png', 'بيانات.csv'], array_column($response->json('data.attachments'), 'name'));
        $this->assertSame(3, AssignmentSubmissionFile::count());
        $this->assertSame(3, $this->storedFileCount());

        $sub = AssignmentSubmission::firstOrFail();
        $this->assertNotNull($sub->submitted_at);
        // Legacy column mirrors the first attachment so old clients keep a working file_url.
        $this->assertNotNull($sub->file_path);
        $this->assertNotNull($response->json('data.file_url'));
    }

    public function test_the_legacy_single_file_field_still_works(): void
    {
        $this->submit(['file' => $this->upload('old-client.pdf', $this->pdfBytes())])
            ->assertStatus(201)
            ->assertJsonPath('data.attachments.0.name', 'old-client.pdf');

        $this->assertSame(1, AssignmentSubmissionFile::count());
    }

    public function test_legacy_file_and_files_array_together(): void
    {
        $this->submit([
            'file'  => $this->upload('a.pdf', $this->pdfBytes()),
            'files' => [$this->upload('b.png', $this->pngBytes())],
        ])->assertStatus(201);

        $this->assertSame(2, AssignmentSubmissionFile::count());
    }

    public function test_text_only_and_no_files_still_work(): void
    {
        $this->submit(['text_answer' => 'just text'])->assertStatus(201)->assertJsonPath('data.attachments', []);
        $this->assertSame(0, AssignmentSubmissionFile::count());
        $this->assertNull(AssignmentSubmission::firstOrFail()->file_path);
    }

    public function test_empty_submission_is_refused_in_arabic(): void
    {
        $response = $this->submit([])->assertStatus(422);
        $this->assertSame('يجب كتابة إجابة نصية أو رفع ملف واحد على الأقل.', $response->json('errors.text_answer.0'));
        $this->assertSame(0, AssignmentSubmission::count());
    }

    // ── limits ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_more_than_five_files_is_rejected_and_nothing_is_stored(): void
    {
        $files = [];
        for ($i = 1; $i <= 6; $i++) {
            $files[] = $this->upload("f{$i}.txt", "file {$i}");
        }

        $response = $this->submit(['files' => $files])->assertStatus(422);

        $this->assertStringContainsString('5', $response->json('errors.files.0'));
        $this->assertSame(0, AssignmentSubmission::count());
        $this->assertSame(0, $this->storedFileCount());
    }

    public function test_total_size_over_the_limit_is_rejected(): void
    {
        // 3 x 9MB PDFs are each within the 10MB per-file limit but 27MB together > 25MB.
        $files = [];
        foreach (['a', 'b', 'c'] as $n) {
            $files[] = $this->upload("{$n}.pdf", $this->padded('%PDF-1.4 '.$n, 9 * 1048576));
        }

        $response = $this->submit(['files' => $files])->assertStatus(422);

        $this->assertStringContainsString('25 ميجابايت', $response->json('errors.files.0'));
        $this->assertSame(0, AssignmentSubmission::count());
        $this->assertSame(0, $this->storedFileCount());
    }

    public static function oversize(): array
    {
        return [
            'image over 5MB'     => ['big.png', 'png', 5 * 1048576 + 1, '5 ميجابايت'],
            'document over 10MB' => ['big.pdf', 'pdf', 10 * 1048576 + 1, '10 ميجابايت'],
            'txt over 2MB'       => ['big.txt', 'txt', 2 * 1048576 + 1, '2 ميجابايت'],
            'zip over 10MB'      => ['big.zip', 'zip', 10 * 1048576 + 1, '10 ميجابايت'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('oversize')]
    public function test_per_file_limits_name_the_limit_in_arabic(string $name, string $kind, int $bytes, string $limitText): void
    {
        $head = match ($kind) {
            'png' => "\x89PNG\r\n\x1A\n",
            'pdf' => '%PDF-1.4 ',
            'zip' => "PK\x03\x04",
            default => 'text ',
        };

        $response = $this->submit(['files' => [$this->upload($name, $this->padded($head, $bytes))]])->assertStatus(422);

        $message = $response->json('errors')['files.0'][0];
        $this->assertStringContainsString('حجم الملف أكبر من المسموح. الحد الأقصى '.$limitText, $message);
        $this->assertStringContainsString($name, $message, 'The message must say WHICH file failed.');
        $this->assertSame($message, $response->json('message'));
        $this->assertSame(0, $this->storedFileCount());
        $this->assertSame(0, AssignmentSubmission::count());
    }

    // ── content verification ───────────────────────────────────────────────────────────────────────────────

    public static function rejectedFiles(): array
    {
        return [
            'exe'                    => ['setup.exe', 'exe', 'نوع الملف غير مدعوم. يمكنك رفع الصور وPDF وWord وExcel والملفات المضغوطة.'],
            'php'                    => ['shell.php', 'hello', 'نوع الملف غير مدعوم'],
            'svg'                    => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'نوع الملف غير مدعوم'],
            'html'                   => ['page.html', '<html></html>', 'نوع الملف غير مدعوم'],
            'php source as jpg'      => ['a.jpg', '<?php echo 1; ?>', 'محتوى الملف لا يطابق امتداده'],
            'MZ program as pdf'      => ['a.pdf', 'exe', 'الملفات التنفيذية'],
            'text as zip'            => ['a.zip', 'not a zip', 'محتوى الملف لا يطابق امتداده'],
            'plain zip as docx'      => ['a.docx', 'plainzip', 'محتوى الملف لا يطابق امتداده'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedFiles')]
    public function test_unsupported_and_disguised_files_are_rejected_with_arabic_errors(string $name, string $kind, string $expected): void
    {
        $bytes = match ($kind) {
            'exe' => $this->exeBytes(),
            'plainzip' => $this->zipBytes(),
            default => $kind,
        };

        $response = $this->submit(['text_answer' => 'x', 'files' => [$this->upload($name, $bytes)]])->assertStatus(422);

        $this->assertStringContainsString($expected, $response->json('errors')['files.0'][0]);
        $this->assertSame(0, AssignmentSubmission::count(), 'assignment must not be marked submitted');
        $this->assertSame(0, $this->storedFileCount());
    }

    public function test_zip_with_traversal_entry_is_rejected(): void
    {
        $response = $this->submit(['files' => [$this->upload('x.zip', $this->zipBytes(['../evil.php' => 'x']))]])->assertStatus(422);

        $this->assertStringContainsString('مسارات غير آمنة', $response->json('errors')['files.0'][0]);
        $this->assertSame(0, $this->storedFileCount());
    }

    public function test_one_bad_file_among_good_ones_stores_nothing_and_does_not_submit(): void
    {
        $response = $this->submit([
            'text_answer' => 'my answer',
            'files' => [
                $this->upload('good.pdf', $this->pdfBytes()),
                $this->upload('bad.exe', $this->exeBytes()),
                $this->upload('good.png', $this->pngBytes()),
            ],
        ])->assertStatus(422);

        $this->assertSame(['files.1'], array_keys($response->json('errors')));
        $this->assertSame(0, $this->storedFileCount());
        $this->assertSame(0, AssignmentSubmissionFile::count());
        $this->assertSame(0, AssignmentSubmission::count());
    }

    public function test_a_failed_resubmission_leaves_the_previous_submission_untouched(): void
    {
        $this->submit(['text_answer' => 'v1', 'files' => [$this->upload('v1.pdf', $this->pdfBytes())]])->assertStatus(201);
        $before = $this->storedFileCount();

        $this->submit(['text_answer' => 'v2', 'files' => [$this->upload('bad.exe', $this->exeBytes())]])->assertStatus(422);

        $sub = AssignmentSubmission::firstOrFail();
        $this->assertSame('v1', $sub->text_answer);
        $this->assertSame($before, $this->storedFileCount());
        $this->assertSame(1, AssignmentSubmissionFile::count());
        $this->assertDatabaseCount('assignment_submission_attempts', 0);
    }

    // ── PHP / server level failures ────────────────────────────────────────────────────────────────────────

    public function test_php_ini_size_failure_gets_an_arabic_422_naming_the_file(): void
    {
        Log::spy();
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        $file = new UploadedFile($tmp, 'تقرير كبير.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);

        $response = $this->submit(['text_answer' => 'x', 'files' => [$file]])->assertStatus(422);

        $message = $response->json('errors')['files.0'][0];
        $this->assertStringContainsString('تقرير كبير.pdf', $message);
        $this->assertStringContainsString('أكبر مما يقبله الخادم', $message);
        $this->assertStringNotContainsString('failed to upload', $response->getContent());
        $this->assertSame(0, AssignmentSubmission::count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'PHP/web-server limit') && isset($ctx['upload_max_filesize'], $ctx['post_max_size'], $ctx['max_file_uploads']))->once();
    }

    public function test_php_partial_upload_failure_message(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        $file = new UploadedFile($tmp, 'half.pdf', 'application/pdf', UPLOAD_ERR_PARTIAL, true);

        $message = $this->submit(['files' => [$file]])->assertStatus(422)->json('errors')['files.0'][0];

        $this->assertStringContainsString('half.pdf', $message);
        $this->assertStringContainsString('انقطع رفع الملف', $message);
    }

    public function test_body_larger_than_post_max_size_returns_413_json(): void
    {
        $user = $this->student;
        $this->actingAs($user, 'sanctum');

        $response = $this->call('POST', "/api/student/assignments/{$this->assignment->id}/submit", [], [], [], [
            'CONTENT_TYPE'   => 'multipart/form-data; boundary=----x',
            'CONTENT_LENGTH' => '9999999999',
            'HTTP_ACCEPT'    => 'application/json',
        ]);

        $response->assertStatus(413)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'payload_too_large')
            ->assertJsonStructure(['message', 'message_ar', 'limits' => ['max_files', 'max_total_bytes', 'groups', 'accept', 'server_post_max_bytes']]);
        // The message states the limit PHP actually enforces, not the application's 25 MB.
        $real = \App\Services\AssignmentUploads\AssignmentUploadPolicy::formatBytesAr(app(PhpUploadLimits::class)->postMaxBytes());
        $this->assertStringContainsString('(الحد '.$real.')', $response->json('message_ar'));
        $this->assertSame(0, AssignmentSubmission::count());
    }

    public function test_route_middleware_refuses_bodies_over_the_app_total_even_when_php_would_accept_them(): void
    {
        $this->app->instance(PhpUploadLimits::class, new class extends PhpUploadLimits {
            public function postMaxBytes(): int
            {
                return 512 * 1048576;
            }
        });

        $middleware = $this->app->make(\App\Http\Middleware\RejectOversizedAssignmentUpload::class);
        $request = fn (int $length) => \Illuminate\Http\Request::create('/x', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----x', 'CONTENT_LENGTH' => (string) $length,
        ]);
        $next = fn () => response('passed through', 200);

        $over = $middleware->handle($request(29 * 1048576), $next);
        $this->assertSame(413, $over->getStatusCode());
        $this->assertSame('payload_too_large', json_decode($over->getContent(), true)['code']);

        // Within the limit it proceeds to normal validation.
        $this->assertSame('passed through', $middleware->handle($request(27 * 1048576), $next)->getContent());
        // JSON bodies are not multipart uploads and are never judged by this guard.
        $json = \Illuminate\Http\Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) (40 * 1048576)]);
        $this->assertSame('passed through', $middleware->handle($json, $next)->getContent());
    }

    // ── resubmission, idempotency, history ─────────────────────────────────────────────────────────────────

    public function test_retrying_identical_files_creates_no_duplicate_attachments_or_files(): void
    {
        $files = fn () => [$this->upload('a.pdf', $this->pdfBytes()), $this->upload('b.png', $this->pngBytes())];

        $this->submit(['files' => $files()])->assertStatus(201);
        $paths = AssignmentSubmissionFile::orderBy('id')->pluck('stored_path')->all();

        $this->submit(['files' => $files()])->assertOk();

        $this->assertSame(2, AssignmentSubmissionFile::where('attempt_number', 0)->count(), 'current set has no duplicates');
        $this->assertSame(2, $this->storedFileCount(), 'identical content is not stored twice');
        $this->assertEqualsCanonicalizing($paths, AssignmentSubmissionFile::where('attempt_number', 0)->pluck('stored_path')->all());
    }

    public function test_identical_content_twice_in_one_request_is_stored_once(): void
    {
        $response = $this->submit(['files' => [$this->upload('a.pdf', $this->pdfBytes()), $this->upload('a-copy.pdf', $this->pdfBytes())]])->assertStatus(201);

        $this->assertCount(1, $response->json('data.attachments'));
        $this->assertSame(1, $this->storedFileCount());
    }

    public function test_resubmission_replaces_the_current_set_and_keeps_the_old_set_in_history(): void
    {
        $this->submit(['files' => [$this->upload('v1-a.pdf', $this->pdfBytes()), $this->upload('v1-b.png', $this->pngBytes())]])->assertStatus(201);
        $submission = AssignmentSubmission::firstOrFail();

        $this->submit(['files' => [$this->upload('v2.csv', "x,y\n1,2\n")]])->assertOk()
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.name', 'v2.csv');

        $this->assertSame(['v2.csv'], AssignmentSubmissionFile::where('attempt_number', 0)->pluck('original_name')->all());
        $this->assertEqualsCanonicalizing(['v1-a.pdf', 'v1-b.png'], AssignmentSubmissionFile::where('attempt_number', 1)->pluck('original_name')->all());
        $this->assertSame(3, $this->storedFileCount(), 'old files are never deleted');

        $history = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/student/assignments/{$this->assignment->id}/history")->assertOk();
        $attachments = $history->json('data.0.attachments');
        $this->assertEqualsCanonicalizing(['v1-a.pdf', 'v1-b.png'], array_column($attachments, 'name'));

        // A superseded file is still downloadable by the owner through its history entry.
        $this->actingAs($this->student, 'sanctum')->get($attachments[0]['download_url'])->assertOk();

        // The instructor sees the same history with downloadable attachments.
        $instructorHistory = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/instructor/submissions/{$submission->id}/history")->assertOk();
        $this->assertCount(2, $instructorHistory->json('data.0.attachments'));
    }

    public function test_text_only_resubmission_keeps_the_current_attachments(): void
    {
        $this->submit(['files' => [$this->upload('keep.pdf', $this->pdfBytes())]])->assertStatus(201);

        $this->submit(['text_answer' => 'second thoughts'])->assertOk()->assertJsonPath('data.attachments.0.name', 'keep.pdf');

        $this->assertSame(1, AssignmentSubmissionFile::where('attempt_number', 0)->count());
        $this->assertSame(1, AssignmentSubmissionFile::where('attempt_number', 1)->count());
    }

    public function test_a_database_failure_removes_the_files_it_just_stored(): void
    {
        AssignmentSubmissionFile::creating(function () {
            throw new \RuntimeException('simulated database failure');
        });

        $this->submit(['text_answer' => 'x', 'files' => [$this->upload('a.pdf', $this->pdfBytes()), $this->upload('b.png', $this->pngBytes())]])
            ->assertStatus(500);

        $this->assertSame(0, $this->storedFileCount(), 'no orphan files after a failed transaction');
        $this->assertSame(0, AssignmentSubmission::count(), 'the submission was rolled back');
    }

    public function test_a_failed_database_transaction_keeps_files_of_the_previous_attempt(): void
    {
        $this->submit(['files' => [$this->upload('old.pdf', $this->pdfBytes())]])->assertStatus(201);
        AssignmentSubmissionFile::creating(function () {
            throw new \RuntimeException('simulated database failure');
        });

        $this->submit(['files' => [$this->upload('new.png', $this->pngBytes())]])->assertStatus(500);

        $this->assertSame(1, $this->storedFileCount());
        $this->assertSame(['old.pdf'], AssignmentSubmissionFile::pluck('original_name')->all());
    }

    // ── authorization & downloads ──────────────────────────────────────────────────────────────────────────

    public function test_not_enrolled_account_gets_403_before_any_validation(): void
    {
        $outsider = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $this->submit(['files' => [$this->upload('bad.exe', $this->exeBytes())]], $outsider)->assertStatus(403);
        $this->assertSame(0, AssignmentSubmission::count());
    }

    private function submittedFile(string $name = 'ملف الطالب.pdf', ?string $bytes = null): AssignmentSubmissionFile
    {
        $this->submit(['files' => [$this->upload($name, $bytes ?? $this->pdfBytes())]])->assertSuccessful();

        return AssignmentSubmissionFile::orderByDesc('id')->firstOrFail();
    }

    private function downloadUrl(AssignmentSubmissionFile $f): string
    {
        return "/api/submissions/{$f->submission_id}/files/{$f->id}/download";
    }

    public function test_owner_and_course_instructor_and_admin_can_download_with_safe_headers(): void
    {
        $file = $this->submittedFile('ملف الطالب.pdf');
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        foreach ([$this->student, $this->teacher, $admin] as $who) {
            $response = $this->actingAs($who, 'sanctum')->get($this->downloadUrl($file))->assertOk();

            $disposition = $response->headers->get('Content-Disposition');
            $this->assertStringStartsWith('attachment;', $disposition);
            $this->assertStringContainsString("filename*=utf-8''".rawurlencode('ملف الطالب.pdf'), $disposition);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertSame($this->pdfBytes(), $response->streamedContent());
        }
    }

    public function test_non_image_non_pdf_is_always_octet_stream(): void
    {
        $docx = $this->submittedFile('r.docx', $this->ooxmlBytes('word'));
        $response = $this->actingAs($this->student, 'sanctum')->get($this->downloadUrl($docx))->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));

        $txt = $this->submittedFile('r.txt', '<script>alert(1)</script>');
        $response = $this->actingAs($this->student, 'sanctum')->get($this->downloadUrl($txt))->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_other_student_unrelated_instructor_and_guest_cannot_download(): void
    {
        $file = $this->submittedFile();

        $otherCourse = Course::factory()->create(['title' => 'Other', 'status' => 'published', 'instructor_id' => $this->profile->id]);
        $otherStudent = $this->enrolledStudent($otherCourse);
        $this->actingAs($otherStudent, 'sanctum')->get($this->downloadUrl($file))->assertStatus(403);

        $stranger = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        Instructor::create(['user_id' => $stranger->id, 'name' => $stranger->name, 'title' => 'Instructor']);
        $this->actingAs($stranger, 'sanctum')->get($this->downloadUrl($file))->assertStatus(403);

        // Same course, different classmate: still not theirs.
        $classmate = $this->enrolledStudent();
        $this->actingAs($classmate, 'sanctum')->get($this->downloadUrl($file))->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $file = $this->submittedFile();
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();

        $this->getJson($this->downloadUrl($file))->assertStatus(401);
    }

    public function test_file_id_from_another_submission_is_not_reachable_idor(): void
    {
        $mine = $this->submittedFile('mine.pdf');

        $classmate = $this->enrolledStudent();
        $this->submit(['files' => [$this->upload('theirs.png', $this->pngBytes())]], $classmate)->assertStatus(201);
        $theirs = AssignmentSubmissionFile::where('original_name', 'theirs.png')->firstOrFail();

        // My submission id + THEIR file id: authorised for the submission, but the file does not belong to it.
        $this->actingAs($this->student, 'sanctum')
            ->get("/api/submissions/{$mine->submission_id}/files/{$theirs->id}/download")->assertStatus(404);

        // THEIR submission id (not mine) is forbidden before the file id is even looked at.
        $this->actingAs($this->student, 'sanctum')
            ->get("/api/submissions/{$theirs->submission_id}/files/{$theirs->id}/download")->assertStatus(403);

        $this->actingAs($this->student, 'sanctum')
            ->get("/api/submissions/{$mine->submission_id}/files/999999/download")->assertStatus(404);
        $this->actingAs($this->student, 'sanctum')
            ->get("/api/submissions/{$mine->submission_id}/files/abc/download")->assertStatus(404);
    }

    public function test_missing_physical_file_is_404_not_500(): void
    {
        $file = $this->submittedFile();
        Storage::disk('private')->delete($file->stored_path);

        $this->actingAs($this->student, 'sanctum')->get($this->downloadUrl($file))->assertStatus(404);
    }

    // ── legacy submissions ─────────────────────────────────────────────────────────────────────────────────

    public function test_legacy_submission_with_only_file_path_still_serialises_and_downloads(): void
    {
        Storage::disk('private')->put('assignment-submissions/legacy-abc.pdf', $this->pdfBytes());
        $submission = AssignmentSubmission::create([
            'assignment_id' => $this->assignment->id, 'user_id' => $this->student->id,
            'file_path' => 'assignment-submissions/legacy-abc.pdf', 'text_answer' => 'old', 'status' => 'submitted', 'submitted_at' => now(),
        ]);

        // Instructor payload: unchanged fields + one synthetic attachment.
        $show = $this->actingAs($this->teacher, 'sanctum')->getJson("/api/instructor/submissions/{$submission->id}")->assertOk();
        $show->assertJsonPath('data.file_url', route('submissions.download', $submission->id))
            ->assertJsonMissingPath('data.file_path')
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.attachments.0.id', 'legacy')
            ->assertJsonPath('data.attachments.0.name', 'legacy-abc.pdf')
            ->assertJsonPath('data.attachments.0.mime', 'application/pdf')
            ->assertJsonPath('data.attachments.0.download_url', route('submissions.download', $submission->id));

        $this->actingAs($this->student, 'sanctum')->get(route('submissions.download', $submission->id))->assertOk();
        $this->assertSame(0, AssignmentSubmissionFile::count());

        // A resubmission with new files replaces the visible set; the legacy file stays reachable through the attempt row.
        $this->submit(['files' => [$this->upload('new.png', $this->pngBytes())]])->assertOk()->assertJsonCount(1, 'data.attachments');
        $this->assertSame('new.png', AssignmentSubmission::firstOrFail()->currentFiles()->first()->original_name);
        Storage::disk('private')->assertExists('assignment-submissions/legacy-abc.pdf');
        $this->assertDatabaseHas('assignment_submission_attempts', ['assignment_submission_id' => $submission->id, 'file_path' => 'assignment-submissions/legacy-abc.pdf']);
    }

    public function test_instructor_resource_lists_all_attachments(): void
    {
        $this->submit(['files' => [$this->upload('a.pdf', $this->pdfBytes()), $this->upload('b.png', $this->pngBytes())]])->assertStatus(201);
        $submission = AssignmentSubmission::firstOrFail();

        $show = $this->actingAs($this->teacher, 'sanctum')->getJson("/api/instructor/submissions/{$submission->id}")->assertOk();
        $this->assertSame(['a.pdf', 'b.png'], array_column($show->json('data.attachments'), 'name'));
        $this->assertStringNotContainsString('stored_path', $show->getContent());
        foreach ($show->json('data.attachments') as $a) {
            $this->actingAs($this->teacher, 'sanctum')->get($a['download_url'])->assertOk();
        }
    }
}
