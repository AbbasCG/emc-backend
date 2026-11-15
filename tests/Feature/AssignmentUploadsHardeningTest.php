<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionAttempt;
use App\Models\AssignmentSubmissionFile;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseModule;
use App\Models\Instructor;
use App\Models\Registration;
use App\Models\User;
use App\Services\AssignmentUploads\AssignmentSubmissionFileService;
use App\Services\AssignmentUploads\AssignmentUploadPolicy;
use App\Services\AssignmentUploads\PhpUploadLimits;
use App\Services\AssignmentUploads\UploadedFileInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsUploadFixtures;
use Tests\TestCase;

/**
 * Round-2 hardening of the assignment upload pipeline: legacy download headers, throttling and cheap-checks-first,
 * concurrency, Arabic error shapes, real-limit wording, payload shapes and query counts.
 */
class AssignmentUploadsHardeningTest extends TestCase
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

    private function enrolledStudent(): User
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        Registration::create([
            'user_id' => $student->id, 'course_id' => $this->course->id,
            'full_name' => $student->name, 'email' => $student->email, 'phone' => '0500000000', 'status' => 'registered',
        ]);

        return $student;
    }

    private function assignmentFor(Course $course, array $extra = []): Assignment
    {
        return Assignment::create($extra + [
            'course_id' => $course->id, 'title' => 'HW', 'status' => 'published', 'max_score' => 100, 'resubmission_allowed' => true,
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

    private function legacySubmission(string $name, string $bytes): AssignmentSubmission
    {
        Storage::disk('private')->put('assignment-submissions/'.$name, $bytes);

        return AssignmentSubmission::updateOrCreate(
            ['assignment_id' => $this->assignment->id, 'user_id' => $this->student->id],
            ['file_path' => 'assignment-submissions/'.$name, 'text_answer' => 'old', 'status' => 'submitted', 'submitted_at' => now()],
        );
    }

    // ── 1. legacy download / inline headers ────────────────────────────────────────────────────────────────

    public function test_instructor_file_never_returns_a_script_holding_txt_as_html_and_always_sends_hardening_headers(): void
    {
        $submission = $this->legacySubmission('note.txt', '<script>alert(document.cookie)</script>');

        $response = $this->actingAs($this->teacher, 'sanctum')->get("/api/instructor/submissions/{$submission->id}/file")->assertOk();

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('html', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_instructor_file_keeps_inline_only_for_pdf_and_images(): void
    {
        $pdf = $this->legacySubmission('doc.pdf', $this->pdfBytes());
        $response = $this->actingAs($this->teacher, 'sanctum')->get("/api/instructor/submissions/{$pdf->id}/file")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // ?download=1 turns inline into an attachment.
        $forced = $this->actingAs($this->teacher, 'sanctum')->get("/api/instructor/submissions/{$pdf->id}/file?download=1")->assertOk();
        $this->assertStringStartsWith('attachment;', $forced->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $forced->headers->get('X-Content-Type-Options'));
    }

    public function test_legacy_html_or_unknown_extensions_are_opaque_attachments_even_when_stored_by_an_old_upload(): void
    {
        foreach (['page.html', 'logo.svg', 'x.php', 'archive.unknown'] as $i => $name) {
            $submission = AssignmentSubmission::updateOrCreate(
                ['assignment_id' => $this->assignment->id, 'user_id' => $this->student->id],
                ['file_path' => 'assignment-submissions/'.$name, 'status' => 'submitted', 'submitted_at' => now()],
            );
            Storage::disk('private')->put('assignment-submissions/'.$name, '<html><script>1</script></html>');

            $response = $this->actingAs($this->teacher, 'sanctum')->get("/api/instructor/submissions/{$submission->id}/file")->assertOk();
            $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'), $name);
            $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'), $name);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $name);
        }
    }

    public function test_row_backed_file_uses_the_verified_mime_and_original_name_on_the_legacy_endpoints(): void
    {
        $this->submit(['files' => [$this->upload('ملف الطالب.docx', $this->ooxmlBytes('word'))]])->assertStatus(201);
        $submission = AssignmentSubmission::firstOrFail();

        $download = $this->actingAs($this->student, 'sanctum')->get("/api/submissions/{$submission->id}/download")->assertOk();
        $this->assertSame('application/octet-stream', $download->headers->get('Content-Type'));
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('ملف الطالب.docx'), $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));

        $inline = $this->actingAs($this->teacher, 'sanctum')->get("/api/instructor/submissions/{$submission->id}/file")->assertOk();
        $this->assertStringStartsWith('attachment;', $inline->headers->get('Content-Disposition'));
        $this->assertStringContainsString(rawurlencode('ملف الطالب.docx'), $inline->headers->get('Content-Disposition'));
    }

    public function test_instructor_file_error_responses_are_arabic_json_with_hardening_headers(): void
    {
        $submission = AssignmentSubmission::create([
            'assignment_id' => $this->assignment->id, 'user_id' => $this->student->id, 'status' => 'submitted', 'submitted_at' => now(), 'text_answer' => 'x',
        ]);

        $none = $this->actingAs($this->teacher, 'sanctum')->get("/api/instructor/submissions/{$submission->id}/file")->assertStatus(404);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $none->json('message'));
        $this->assertSame('nosniff', $none->headers->get('X-Content-Type-Options'));

        $stranger = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $forbidden = $this->actingAs($stranger, 'sanctum')->get("/api/instructor/submissions/{$submission->id}/file")->assertStatus(403);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $forbidden->json('message'));

        $legacy = $this->legacySubmission('a.pdf', $this->pdfBytes());
        $this->actingAs($stranger, 'sanctum')->getJson("/api/submissions/{$legacy->id}/download")->assertStatus(403)
            ->assertJsonPath('message', 'غير مصرح لك بالوصول إلى هذا التسليم.');
    }

    // ── 3. throttling, cheap checks first, one log line ───────────────────────────────────────────────────

    public function test_submit_is_throttled_per_user_with_an_arabic_429(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->assertNotSame(429, $this->submit(['text_answer' => "v{$i}"])->getStatusCode(), "request {$i}");
        }

        $blocked = $this->submit(['text_answer' => 'one too many'])->assertStatus(429);
        $this->assertSame('too_many_requests', $blocked->json('code'));
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $blocked->json('message'));
        $this->assertGreaterThan(0, $blocked->json('retry_after'));
        $this->assertNotNull($blocked->headers->get('Retry-After'));

        // Another account is not affected (the bucket is per user, never per IP).
        $other = $this->enrolledStudent();
        $this->assertSame(201, $this->submit(['text_answer' => 'fine'], $other)->getStatusCode());
    }

    public function test_downloads_are_throttled_per_user(): void
    {
        $this->actingAs($this->student, 'sanctum');
        for ($i = 1; $i <= 60; $i++) {
            $this->assertNotSame(429, $this->getJson('/api/submissions/1/files/1/download')->getStatusCode(), "request {$i}");
        }

        $blocked = $this->getJson('/api/submissions/1/files/1/download')->assertStatus(429);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $blocked->json('message'));
    }

    public function test_an_already_submitted_assignment_is_refused_before_any_file_is_inspected(): void
    {
        $assignment = $this->assignmentFor($this->course, ['resubmission_allowed' => false]);
        $this->submit(['text_answer' => 'first'], null, $assignment)->assertStatus(201);

        // Fail the test if the (expensive) inspector is ever consulted for the second request.
        $this->mock(UploadedFileInspector::class, fn ($m) => $m->shouldNotReceive('inspect'));

        $response = $this->submit(['files' => [$this->upload('a.pdf', $this->pdfBytes()), $this->upload('b.png', $this->pngBytes())]], null, $assignment)
            ->assertStatus(422);

        $this->assertSame('تم تسليم هذا الواجب مسبقاً ولا يمكن إعادة التسليم إلا بطلب من المدرس.', $response->json('errors.assignment.0'));
        $this->assertSame($response->json('errors.assignment.0'), $response->json('message'));
        $this->assertFalse($response->json('success'));
        $this->assertSame(0, $this->storedFileCount());
        $this->assertSame(0, AssignmentSubmissionFile::count());
    }

    public function test_max_attempts_refusal_is_arabic_and_stores_nothing(): void
    {
        $assignment = $this->assignmentFor($this->course, ['max_attempts' => 2]);
        $this->submit(['text_answer' => 'a1'], null, $assignment)->assertStatus(201);
        $this->submit(['text_answer' => 'a2'], null, $assignment)->assertOk();

        $response = $this->submit(['text_answer' => 'a3', 'files' => [$this->upload('x.pdf', $this->pdfBytes())]], null, $assignment)->assertStatus(422);

        $this->assertStringContainsString('الحد الأقصى لعدد المحاولات', $response->json('message'));
        $this->assertSame(0, $this->storedFileCount());
        $this->assertSame('a2', AssignmentSubmission::firstOrFail()->text_answer);
    }

    public function test_needs_revision_resubmission_with_files_is_accepted_even_when_resubmission_is_disabled(): void
    {
        $assignment = $this->assignmentFor($this->course, ['resubmission_allowed' => false]);
        $this->submit(['files' => [$this->upload('v1.pdf', $this->pdfBytes())]], null, $assignment)->assertStatus(201);
        AssignmentSubmission::firstOrFail()->update(['status' => 'needs_revision']);

        $response = $this->submit(['files' => [$this->upload('v2.png', $this->pngBytes())]], null, $assignment)->assertOk();

        $this->assertSame('تمت إعادة تسليم الواجب بنجاح.', $response->json('message'));
        $this->assertSame(['v2.png'], array_column($response->json('data.attachments'), 'name'));
        $this->assertSame(['v1.pdf'], AssignmentSubmissionFile::where('attempt_number', 1)->pluck('original_name')->all());
        $this->assertNotNull(AssignmentSubmission::firstOrFail()->resubmitted_at);
    }

    public function test_legacy_file_path_submission_survives_a_text_only_resubmission(): void
    {
        $this->legacySubmission('legacy.pdf', $this->pdfBytes());

        $response = $this->submit(['text_answer' => 'updated text'])->assertOk();

        $this->assertSame('legacy.pdf', $response->json('data.attachments.0.name'));
        $this->assertSame('legacy', $response->json('data.attachments.0.id'));
        $this->assertSame('assignment-submissions/legacy.pdf', AssignmentSubmission::firstOrFail()->file_path);
    }

    public function test_several_rejected_files_write_one_log_line(): void
    {
        Log::spy();

        $this->submit(['files' => [
            $this->upload('a.exe', $this->exeBytes()),
            $this->upload('b.php', 'x'),
            $this->upload('c.pdf', 'not a pdf'),
        ]])->assertStatus(422);

        Log::shouldHaveReceived('warning')->once();
    }

    // ── 4. concurrency ────────────────────────────────────────────────────────────────────────────────────

    public function test_a_unique_key_collision_is_a_409_and_the_loser_cleans_up_without_touching_the_winner(): void
    {
        // The winner: a row + its physical file that already exist when the loser's INSERT runs.
        Storage::disk('private')->put('assignment-submissions/winner.pdf', $this->pdfBytes());
        $sha = hash('sha256', $this->pdfBytes());

        AssignmentSubmissionFile::creating(function (AssignmentSubmissionFile $row) use ($sha) {
            static $planted = false;
            if (! $planted) {
                $planted = true;
                DB::table('assignment_submission_files')->insert([
                    'submission_id' => $row->submission_id, 'attempt_number' => 0, 'original_name' => 'winner.pdf',
                    'stored_path' => 'assignment-submissions/winner.pdf', 'mime' => 'application/pdf', 'extension' => 'pdf',
                    'size_bytes' => 10, 'sha256' => $sha, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $response = $this->submit(['files' => [$this->upload('loser.pdf', $this->pdfBytes())]])->assertStatus(409);

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $response->json('message'));
        $this->assertSame($response->json('errors.assignment.0'), $response->json('message'));
        Storage::disk('private')->assertExists('assignment-submissions/winner.pdf');
        $this->assertSame(1, $this->storedFileCount(), 'only the winner file remains; the loser file was discarded');
        // (The planted winner ROW shares the test's single transaction, so it is rolled back with the loser's work here; in
        // production the winner commits separately. What matters, and is asserted above, is that its FILE is untouched.)
    }

    public function test_the_rules_are_rechecked_under_the_row_lock_and_the_stored_files_are_discarded(): void
    {
        $assignment = $this->assignmentFor($this->course, ['max_attempts' => 2]);
        $this->submit(['text_answer' => 'first'], null, $assignment)->assertStatus(201);
        $submission = AssignmentSubmission::firstOrFail();

        // While this request is between its first check and its transaction, "another request" completes an attempt.
        $raced = false;
        DB::listen(function ($query) use (&$raced, $submission) {
            if ($raced || ! str_contains($query->sql, 'assignment_submission_files') || ! str_starts_with(strtolower($query->sql), 'select "stored_path"')) {
                return;
            }
            $raced = true;
            AssignmentSubmissionAttempt::create([
                'assignment_submission_id' => $submission->id, 'attempt_number' => 1, 'text_answer' => 'racer', 'status' => 'submitted',
            ]);
        });

        $response = $this->submit(['text_answer' => 'second', 'files' => [$this->upload('late.pdf', $this->pdfBytes())]], null, $assignment)->assertStatus(422);

        $this->assertTrue($raced, 'the race simulation must have fired');
        $this->assertStringContainsString('الحد الأقصى لعدد المحاولات', $response->json('message'));
        $this->assertSame(0, $this->storedFileCount(), 'the loser stored nothing that survives');
        $this->assertSame('first', $submission->fresh()->text_answer);
        $this->assertSame(0, AssignmentSubmissionFile::count());
    }

    // ── 5. English strings, storage failure, success wording ──────────────────────────────────────────────

    public function test_a_failed_disk_write_is_an_arabic_503_and_nothing_is_submitted(): void
    {
        $this->partialMock(AssignmentSubmissionFileService::class, function ($mock) {
            $mock->shouldReceive('store')->andThrow(new \RuntimeException('disk full'));
        });

        $response = $this->submit(['text_answer' => 'x', 'files' => [$this->upload('a.pdf', $this->pdfBytes())]])->assertStatus(503);

        $this->assertSame('storage_failed', $response->json('code'));
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $response->json('message'));
        $this->assertStringNotContainsString('disk full', $response->getContent());
        $this->assertSame(0, AssignmentSubmission::count());
    }

    public function test_success_messages_are_arabic(): void
    {
        $this->assertSame('تم تسليم الواجب بنجاح.', $this->submit(['text_answer' => 'a'])->assertStatus(201)->json('message'));
        $this->assertSame('تمت إعادة تسليم الواجب بنجاح.', $this->submit(['text_answer' => 'b'])->assertOk()->json('message'));
    }

    public function test_file_download_errors_on_the_new_route_are_arabic(): void
    {
        $this->submit(['files' => [$this->upload('a.pdf', $this->pdfBytes())]])->assertStatus(201);
        $file = AssignmentSubmissionFile::firstOrFail();
        $stranger = $this->enrolledStudent();

        $this->actingAs($stranger, 'sanctum')->getJson("/api/submissions/{$file->submission_id}/files/{$file->id}/download")
            ->assertStatus(403)->assertJsonPath('message', 'غير مصرح لك بالوصول إلى هذا الملف.');
        $this->actingAs($this->student, 'sanctum')->getJson("/api/submissions/{$file->submission_id}/files/99999/download")
            ->assertStatus(404)->assertJsonPath('message', 'الملف غير موجود.');
    }

    // ── 6. real limits in messages ────────────────────────────────────────────────────────────────────────

    public function test_php_upload_error_message_states_the_real_limit_in_megabytes_not_the_raw_ini_value(): void
    {
        $file = new UploadedFile(tempnam(sys_get_temp_dir(), 'up'), 'big.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);

        $message = $this->submit(['files' => [$file]])->assertStatus(422)->json('errors')['files.0'][0];

        $limit = AssignmentUploadPolicy::formatBytesAr(AssignmentUploadPolicy::iniToBytes(ini_get('upload_max_filesize')));
        $this->assertStringContainsString("(الحد {$limit})", $message);
        $this->assertStringContainsString('ميجابايت', $message);
        $this->assertDoesNotMatchRegularExpression('/\d+M\)/', $message);
    }

    public function test_ini_shorthand_edge_cases(): void
    {
        $this->assertSame(-1, AssignmentUploadPolicy::iniToBytes('-1'));
        $this->assertSame(8 * 1048576, AssignmentUploadPolicy::iniToBytes('8m'));
        $this->assertSame(1073741824, AssignmentUploadPolicy::iniToBytes('1g'));
        $this->assertSame(16 * 1048576, AssignmentUploadPolicy::iniToBytes(' 16M '));
        $this->assertSame(0, AssignmentUploadPolicy::iniToBytes('abc'));
        $this->assertSame(0, AssignmentUploadPolicy::iniToBytes(''));
        $this->assertSame(0, AssignmentUploadPolicy::iniToBytes(false));
        $this->assertSame(0, AssignmentUploadPolicy::iniToBytes(null));
        $this->assertSame(5242880, AssignmentUploadPolicy::iniToBytes(5242880));
    }

    // ── 7. payload shapes and query counts ────────────────────────────────────────────────────────────────

    public function test_instructor_payloads_never_expose_the_storage_path_and_carry_attachments(): void
    {
        $this->submit(['files' => [$this->upload('a.pdf', $this->pdfBytes())]])->assertStatus(201);
        $submission = AssignmentSubmission::firstOrFail();
        $path = $submission->file_path;
        $this->assertNotNull($path);

        $show = $this->actingAs($this->teacher, 'sanctum')->getJson("/api/instructor/submissions/{$submission->id}")->assertOk();
        $show->assertJsonMissingPath('data.file_path')->assertJsonPath('data.has_file', true)->assertJsonPath('data.attachments.0.name', 'a.pdf');
        $this->assertStringNotContainsString($path, $show->getContent());

        $index = $this->actingAs($this->teacher, 'sanctum')->getJson('/api/instructor/submissions')->assertOk();
        $this->assertSame('a.pdf', $index->json('data.0.attachments.0.name'));
        $this->assertStringNotContainsString($path, $index->getContent());

        $forAssignment = $this->actingAs($this->teacher, 'sanctum')->getJson("/api/instructor/assignments/{$this->assignment->id}/submissions")->assertOk();
        $this->assertSame('a.pdf', $forAssignment->json('data.0.attachments.0.name'));

        $review = $this->actingAs($this->teacher, 'sanctum')->putJson("/api/instructor/submissions/{$submission->id}/review", ['status' => 'reviewed', 'score' => 80, 'feedback' => 'ok'])->assertOk();
        $this->assertSame('a.pdf', $review->json('data.attachments.0.name'));
        $this->assertStringNotContainsString($path, $review->getContent());
    }

    public function test_student_assignment_show_carries_my_submission_attachments(): void
    {
        $this->submit(['files' => [$this->upload('mine.pdf', $this->pdfBytes())]])->assertStatus(201);

        $this->actingAs($this->student, 'sanctum')->getJson("/api/student/assignments/{$this->assignment->id}")->assertOk()
            ->assertJsonPath('data.my_submission.attachments.0.name', 'mine.pdf');
    }

    public function test_student_assignment_list_returns_attachments_without_per_row_queries(): void
    {
        $rows = 6;
        for ($i = 1; $i <= $rows; $i++) {
            $ca = CourseAssignment::create(['course_id' => $this->course->id, 'title' => "CA{$i}", 'status' => 'published', 'is_visible' => true, 'deadline' => now()->addDays($i)]);
            $lms = Assignment::create(['course_id' => $this->course->id, 'course_assignment_id' => $ca->id, 'title' => "CA{$i}", 'status' => 'published', 'max_score' => 100]);
            $this->submit(['files' => [$this->upload("f{$i}.pdf", $this->pdfBytes().$i)]], null, $lms)->assertStatus(201);
        }
        $this->app['cache']->flush(); // submissions above used the throttle bucket; keep the GET independent of it

        DB::enableQueryLog();
        $response = $this->actingAs($this->student, 'sanctum')->getJson('/api/student/assignments')->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount($rows, $response->json('data'));
        foreach ($response->json('data') as $row) {
            $this->assertCount(1, $row['my_submission']['attachments']);
        }

        $this->assertLessThanOrEqual(1, $this->countQueriesOn($log, 'assignment_submission_files'), 'attachments are loaded once for the whole page');
        $this->assertLessThanOrEqual(1, $this->countQueriesOn($log, 'assignment_submissions'), 'submissions are loaded once for the whole page');
    }

    public function test_learn_endpoint_returns_attachments_for_course_and_module_level_assignments(): void
    {
        $module = CourseModule::create(['course_id' => $this->course->id, 'title' => 'M1', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);
        $inModule = CourseAssignment::create(['course_id' => $this->course->id, 'module_id' => $module->id, 'title' => 'In module', 'status' => 'published', 'is_visible' => true]);
        $courseLevel = CourseAssignment::create(['course_id' => $this->course->id, 'title' => 'Course level', 'status' => 'published', 'is_visible' => true]);
        $lmsModule = Assignment::create(['course_id' => $this->course->id, 'course_assignment_id' => $inModule->id, 'title' => 'In module', 'status' => 'published', 'max_score' => 100]);
        $lmsCourse = Assignment::create(['course_id' => $this->course->id, 'course_assignment_id' => $courseLevel->id, 'title' => 'Course level', 'status' => 'published', 'max_score' => 100]);

        $this->submit(['files' => [$this->upload('m.pdf', $this->pdfBytes())]], null, $lmsModule)->assertStatus(201);
        $this->submit(['files' => [$this->upload('c.png', $this->pngBytes())]], null, $lmsCourse)->assertStatus(201);

        $response = $this->withoutMiddleware(\App\Http\Middleware\PlacementTestRequired::class)
            ->actingAs($this->student, 'sanctum')->getJson("/api/student/learn/{$this->course->id}")->assertOk();

        $moduleAssignment = collect($response->json('data.modules.0.assignments') ?? $response->json('modules.0.assignments') ?? [])->first();
        $courseAssignment = collect($response->json('data.assignments') ?? $response->json('assignments') ?? [])->first();

        $this->assertNotNull($moduleAssignment, 'module-level assignment present in the learn payload');
        $this->assertNotNull($courseAssignment, 'course-level assignment present in the learn payload');
        $this->assertSame('m.pdf', $moduleAssignment['my_submission']['attachments'][0]['name']);
        $this->assertSame('c.png', $courseAssignment['my_submission']['attachments'][0]['name']);
    }

    public function test_admin_assignment_detail_lists_attachments_and_keeps_file_url(): void
    {
        $ca = CourseAssignment::create(['course_id' => $this->course->id, 'title' => 'HW', 'status' => 'published', 'is_visible' => true, 'deadline' => now()->addWeek()]);
        $lms = Assignment::create(['course_id' => $this->course->id, 'course_assignment_id' => $ca->id, 'title' => 'HW', 'status' => 'published', 'max_score' => 100]);
        $this->submit(['files' => [$this->upload('a.pdf', $this->pdfBytes()), $this->upload('b.png', $this->pngBytes())]], null, $lms)->assertStatus(201);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/assignments/{$ca->id}")->assertOk();

        $submission = $response->json('data.submissions.0');
        $this->assertSame(['a.pdf', 'b.png'], array_column($submission['attachments'], 'name'));
        $this->assertNotNull($submission['file_url']);
        $this->assertTrue($submission['has_file']);
    }

    // ── Round 3: lock paths ───────────────────────────────────────────────────────────────────────────────

    private function fakeLock(?\Closure $configure = null): void
    {
        $lock = \Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $configure ? $configure($lock) : $lock->shouldReceive('block')->andReturn(true)->shouldReceive('release')->andReturn(true);
        \Illuminate\Support\Facades\Cache::partialMock()->shouldReceive('lock')->andReturn($lock);
    }

    public function test_a_lock_timeout_is_an_arabic_409_and_nothing_is_written(): void
    {
        $this->fakeLock(fn ($lock) => $lock->shouldReceive('block')->andThrow(new \Illuminate\Contracts\Cache\LockTimeoutException()));

        $response = $this->submit(['text_answer' => 'x', 'files' => [$this->upload('a.pdf', $this->pdfBytes())]])->assertStatus(409);

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $response->json('message'));
        $this->assertSame(0, AssignmentSubmission::count());
        $this->assertSame(0, $this->storedFileCount());
    }

    public function test_a_cache_store_without_lock_support_falls_back_and_the_submission_still_works(): void
    {
        \Illuminate\Support\Facades\Cache::partialMock()->shouldReceive('lock')->andThrow(new \BadMethodCallException('This cache store does not support locking.'));

        $this->submit(['files' => [$this->upload('a.pdf', $this->pdfBytes())]])->assertStatus(201)
            ->assertJsonPath('data.attachments.0.name', 'a.pdf');
    }

    public function test_a_failing_lock_release_never_changes_a_committed_submission_into_an_error(): void
    {
        Log::spy();
        $this->fakeLock(fn ($lock) => $lock->shouldReceive('block')->andReturn(true)->shouldReceive('release')->andThrow(new \RuntimeException('lock expired')));

        $response = $this->submit(['files' => [$this->upload('a.pdf', $this->pdfBytes())]])->assertStatus(201);

        $this->assertSame('تم تسليم الواجب بنجاح.', $response->json('message'));
        $this->assertSame(1, AssignmentSubmissionFile::count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'release the assignment submission lock'))->once();
    }

    // ── Round 3: database error mapping ───────────────────────────────────────────────────────────────────

    private function dbError(string $sqlstate, int $driverCode, string $message): \Illuminate\Database\QueryException
    {
        $pdo = new \PDOException($message);
        $pdo->errorInfo = [$sqlstate, $driverCode, $message];

        return new \Illuminate\Database\QueryException('mysql', 'insert into assignment_submission_files ...', [], $pdo);
    }

    public static function raceErrors(): array
    {
        return [
            'MySQL duplicate key 1062'  => ['23000', 1062, "Duplicate entry 'x' for key 'asf_submission_attempt_sha_unique'"],
            'serialization failure'     => ['40001', 0, 'Serialization failure'],
            'MySQL deadlock 1213'       => ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'],
            'MySQL lock wait timeout'   => ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('raceErrors')]
    public function test_races_and_deadlocks_are_an_arabic_409_and_the_loser_cleans_up(string $state, int $code, string $message): void
    {
        AssignmentSubmissionFile::creating(function () use ($state, $code, $message) {
            throw $this->dbError($state, $code, $message);
        });

        $response = $this->submit(['files' => [$this->upload('a.pdf', $this->pdfBytes())]])->assertStatus(409);

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $response->json('message'));
        $this->assertSame(0, $this->storedFileCount());
        // (No row-count assertion here: for a deadlock Laravel deliberately leaves the rollback to the OUTERMOST transaction, which in this
        // test is the RefreshDatabase wrapper. In production the request transaction is the outermost one and rolls back itself.)
    }

    public function test_a_non_unique_integrity_violation_is_a_logged_arabic_500_not_a_misleading_409(): void
    {
        Log::spy();
        AssignmentSubmissionFile::creating(function () {
            throw $this->dbError('23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails');
        });

        $response = $this->submit(['files' => [$this->upload('a.pdf', $this->pdfBytes())]])->assertStatus(500);

        $this->assertSame('submission_failed', $response->json('code'));
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $response->json('message'));
        $this->assertStringNotContainsString('foreign key', $response->getContent());
        $this->assertSame(0, $this->storedFileCount());
        $this->assertSame(0, AssignmentSubmission::count());
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => str_contains($message, 'unexpected database error') && ($context['driver_code'] ?? null) === 1452)->once();
    }

    // ── Round 3: request-level consistency ────────────────────────────────────────────────────────────────

    public function test_the_text_zero_counts_as_an_answer_in_the_request_and_in_the_controller(): void
    {
        $this->submit(['text_answer' => '0'])->assertStatus(201)->assertJsonPath('data.text_answer', '0');
    }

    public function test_files_are_not_inspected_when_field_level_validation_already_failed(): void
    {
        $this->mock(UploadedFileInspector::class, fn ($m) => $m->shouldNotReceive('inspect'));

        $response = $this->submit(['text_answer' => str_repeat('a', 20001), 'files' => [$this->upload('a.pdf', $this->pdfBytes())]])->assertStatus(422);

        $this->assertArrayHasKey('text_answer', $response->json('errors'));
        $this->assertSame(0, $this->storedFileCount());
    }

    public function test_inline_images_get_a_locked_down_csp_and_pdfs_do_not(): void
    {
        $png = $this->legacySubmission('p.png', $this->pngBytes());
        $response = $this->actingAs($this->teacher, 'sanctum')->get("/api/instructor/submissions/{$png->id}/file")->assertOk();
        $this->assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));

        $pdf = $this->legacySubmission('p.pdf', $this->pdfBytes());
        $response = $this->actingAs($this->teacher, 'sanctum')->get("/api/instructor/submissions/{$pdf->id}/file")->assertOk();
        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    // ── Round 3: boot-time misconfiguration log is rate limited ───────────────────────────────────────────

    private function bootCheck(): void
    {
        $provider = new \App\Providers\AppServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'reportAssignmentUploadMisconfiguration');
        $method->invoke($provider);
    }

    public function test_a_misconfiguration_is_logged_at_most_once_per_hour(): void
    {
        Log::spy();
        \Illuminate\Support\Facades\Cache::forget('assignment_uploads:config_error_logged');
        config(['assignment_uploads.max_total_mb' => 1]);

        $this->bootCheck();
        $this->bootCheck();
        $this->bootCheck();

        Log::shouldHaveReceived('critical')->once();
    }

    public function test_a_healthy_configuration_logs_nothing(): void
    {
        Log::spy();
        $this->bootCheck();

        Log::shouldNotHaveReceived('critical');
    }

    public function test_zero_and_negative_guards_are_configuration_errors(): void
    {
        $policy = app(AssignmentUploadPolicy::class);

        foreach ([
            'assignment_uploads.zip.max_entries'         => 0,
            'assignment_uploads.zip.max_ratio'           => -1,
            'assignment_uploads.zip.max_uncompressed_mb' => 0,
            'assignment_uploads.max_files'               => 0,
            'assignment_uploads.groups.images.max_mb'    => 0,
            'assignment_uploads.max_total_mb'            => -5,
        ] as $key => $value) {
            config([$key => $value]);
            $this->assertNotEmpty($policy->configErrors(), "{$key}={$value} must be reported");
            $this->refreshApplication();
            $policy = app(AssignmentUploadPolicy::class);
        }
    }

    // ── Round 3: query counts on every student list ───────────────────────────────────────────────────────

    /** Counts queries against a table, however the driver quotes identifiers (`t`, "t", [t], bare). */
    private function countQueriesOn(array $log, string $table): int
    {
        return collect($log)->filter(fn ($q) => preg_match('/\bfrom\s+[`"\[]?'.preg_quote($table, '/').'[`"\]]?(\s|$)/i', $q['query']) === 1)->count();
    }

    public function test_the_student_dashboard_loads_attachments_in_one_query(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $ca = CourseAssignment::create(['course_id' => $this->course->id, 'title' => "D{$i}", 'status' => 'published', 'is_visible' => true, 'deadline' => now()->addDays($i)]);
            $lms = Assignment::create(['course_id' => $this->course->id, 'course_assignment_id' => $ca->id, 'title' => "D{$i}", 'status' => 'published', 'max_score' => 100]);
            $this->submit(['files' => [$this->upload("d{$i}.pdf", $this->pdfBytes().$i)]], null, $lms)->assertStatus(201);
        }
        $this->app['cache']->flush();

        DB::enableQueryLog();
        $response = $this->actingAs($this->student, 'sanctum')->getJson('/api/student/dashboard')->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $withFiles = collect($response->json('data.assignments'))->filter(fn ($a) => ! empty($a['my_submission']['attachments']));
        $this->assertCount(5, $withFiles);
        $this->assertLessThanOrEqual(1, $this->countQueriesOn($log, 'assignment_submission_files'), 'attachments of every row come from ONE query');
    }

    public function test_the_learn_endpoint_loads_attachments_without_per_row_queries(): void
    {
        $module = CourseModule::create(['course_id' => $this->course->id, 'title' => 'M', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);
        foreach (range(1, 4) as $i) {
            foreach ([['module_id' => $module->id, 'tag' => 'M'], ['module_id' => null, 'tag' => 'C']] as $kind) {
                $ca = CourseAssignment::create(['course_id' => $this->course->id, 'module_id' => $kind['module_id'], 'title' => $kind['tag'].$i, 'status' => 'published', 'is_visible' => true]);
                $lms = Assignment::create(['course_id' => $this->course->id, 'course_assignment_id' => $ca->id, 'title' => $kind['tag'].$i, 'status' => 'published', 'max_score' => 100]);
                $this->submit(['files' => [$this->upload($kind['tag'].$i.'.pdf', $this->pdfBytes().$kind['tag'].$i)]], null, $lms)->assertStatus(201);
            }
        }
        $this->app['cache']->flush();

        DB::enableQueryLog();
        $response = $this->withoutMiddleware(\App\Http\Middleware\PlacementTestRequired::class)
            ->actingAs($this->student, 'sanctum')->getJson("/api/student/learn/{$this->course->id}")->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(4, $response->json('data.modules.0.assignments'));
        $this->assertCount(4, $response->json('data.assignments'));
        foreach ([...$response->json('data.modules.0.assignments'), ...$response->json('data.assignments')] as $row) {
            $this->assertCount(1, $row['my_submission']['attachments']);
        }
        // One preload for the module-level rows, one for the course-level rows: independent of the row count.
        $this->assertLessThanOrEqual(2, $this->countQueriesOn($log, 'assignment_submission_files'));
    }
}
