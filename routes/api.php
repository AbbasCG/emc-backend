<?php

use App\Http\Controllers\Admin\AdminAmbassadorApplicationFileController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\Admin\AdminLmsAssignmentController;
use App\Http\Controllers\Admin\AdminPartnerPortalController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AutomationRuleController;
use App\Http\Controllers\Admin\CertificateAnalyticsController;
use App\Http\Controllers\Admin\CertificateBatchController;
use App\Http\Controllers\Admin\CertificateController;
use App\Http\Controllers\Admin\CertificateTemplateController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CourseModuleController;
use App\Http\Controllers\Admin\DocumentFolderController;
use App\Http\Controllers\Admin\FinanceAccountController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\ManualPaymentController;
use App\Http\Controllers\Admin\ProductUpdateController;
use App\Http\Controllers\Admin\KnowledgeArticleController;
use App\Http\Controllers\Admin\KnowledgeCategoryController;
use App\Http\Controllers\Admin\KpiController;
use App\Http\Controllers\Admin\LessonController;
use App\Http\Controllers\Admin\QuizController;
use App\Http\Controllers\Admin\QualityController;
use App\Http\Controllers\Admin\QualityReviewController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ScholarshipController;
use App\Http\Controllers\AiConversationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Partner\PartnerDashboardController;
use App\Http\Controllers\Partner\PartnerDocumentController;
use App\Http\Controllers\Partner\PartnerProgramController;
use App\Http\Controllers\Partner\PartnerReportController;
use App\Http\Controllers\Public\CertificateVerificationController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Student\StudentCertificateController;
use App\Http\Controllers\Student\StudentLearningController;
use App\Http\Controllers\Admin\AdminLmsAttendanceController;
use App\Http\Controllers\Admin\AdminLmsEvaluationController;
use App\Http\Controllers\Admin\AdminLmsMaterialController;
use App\Http\Controllers\Admin\AdminLmsProgressController;
use App\Http\Controllers\Admin\AdminLmsSessionController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\EmailLogsController;
use App\Http\Controllers\Admin\EmailSettingsController;
use App\Http\Controllers\Admin\DynamicFormController;
use App\Http\Controllers\Admin\MarketingItemController;
use App\Http\Controllers\Admin\MeetingController;
use App\Http\Controllers\Admin\PartnerController;
use App\Http\Controllers\Admin\PartnershipRequestAdminController;
use App\Http\Controllers\Admin\SupportTicketController as AdminSupportTicketController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\VolunteerController;
use App\Http\Controllers\Admin\AdminVolunteerRequestController;
use App\Http\Controllers\Admin\HrVolunteerProfileController;
use App\Http\Controllers\VolunteerHrProfileController;
use App\Http\Controllers\Admin\AdminMemberController;
use App\Http\Controllers\Admin\AdminTeamProfileController;
use App\Http\Controllers\Public\VolunteerRequestController as PublicVolunteerRequestController;
use App\Http\Controllers\Public\AmbassadorApplicationController as PublicAmbassadorController;
use App\Http\Controllers\Admin\AdminAmbassadorApplicationController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminPaymentController;
use App\Http\Controllers\AdminTrackController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\Instructor\InstructorAssignmentController;
use App\Http\Controllers\Instructor\InstructorCourseController;
use App\Http\Controllers\Instructor\InstructorDashboardController;
use App\Http\Controllers\Instructor\InstructorSessionController;
use App\Http\Controllers\Instructor\InstructorStudentController;
use App\Http\Controllers\Instructor\InstructorSubmissionController;
use App\Http\Controllers\InstructorController;
use App\Http\Controllers\Lms\SessionAttendanceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Platform\PlatformScaleController;
use App\Http\Controllers\Public\PartnershipRequestController as PublicPartnershipRequestController;
use App\Http\Controllers\Public\PublicFormController;
use App\Http\Controllers\Public\SupportTicketController as PublicSupportTicketController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\Student\StudentAssignmentController;
use App\Http\Controllers\Student\StudentLessonController;
use App\Http\Controllers\Student\StudentDashboardController;
use App\Http\Controllers\Student\StudentEvaluationController;
use App\Http\Controllers\Student\StudentMaterialController;
use App\Http\Controllers\Student\StudentProgressController;
use App\Http\Controllers\Student\StudentSessionController;
use App\Http\Controllers\TrackController;
use App\Http\Controllers\WorkshopRequestController;
// Phase 7 imports
use App\Http\Controllers\Mobile\MobileController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\Admin\WebhookEndpointController;
use App\Http\Controllers\Admin\IntegrationSettingController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\NotificationTemplateController;
use App\Http\Controllers\Admin\ExternalEventLogController;
// RBAC imports
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserRoleController;
// Phase 8 imports
use App\Http\Controllers\AiContentGenerationController;
use App\Http\Controllers\AiMeetingController;
use App\Http\Controllers\AiRecommendationController;
use App\Http\Controllers\AiSemanticSearchController;
use App\Http\Controllers\Admin\AI\AiAutomationController;
use App\Http\Controllers\Admin\AI\AiInsightController;
use App\Http\Controllers\Admin\AI\AiPromptController;
use App\Http\Controllers\Admin\AI\AiUsageController;
use App\Http\Controllers\Hr\HrDashboardController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWorkshopController;
use App\Http\Controllers\Admin\AdminTeamMemberController;
use App\Http\Controllers\Admin\AdminAuditLogController;
use App\Http\Controllers\Admin\AdminCourseController;
use App\Http\Controllers\Admin\AdminInstructorController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\Student\StudentOrderController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Instructor\InstructorWorkshopController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Student\StudentRegistrationsController;
use App\Http\Controllers\Student\StudentPlacementTestController;
use App\Http\Controllers\Student\StudentOralAssessmentController;
use App\Http\Controllers\Instructor\InstructorPlacementController;
use App\Http\Controllers\Instructor\InstructorAvailabilityController;
use App\Http\Controllers\Instructor\InstructorOralBookingController;
use App\Http\Controllers\Admin\AdminPlacementController;
use App\Http\Controllers\Public\ConsultantApplicationController as PublicConsultantApplicationController;
use App\Http\Controllers\Admin\ConsultantApplicationController as AdminConsultantApplicationController;
use App\Http\Controllers\Operations\MeetingReportController;
use App\Http\Controllers\Operations\WeeklyReportController;
use App\Http\Controllers\Operations\VolunteerPointsController;
use App\Http\Controllers\Operations\OperationsBoardController;
use Illuminate\Support\Facades\Route;

// ─── Health (no throttle — used by uptime monitors) ──────────────────────────
Route::get('/health', \App\Http\Controllers\HealthController::class);

// ─── Auth (throttle:auth — 5 req/min per IP, see AppServiceProvider::boot) ───
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register',       [AuthController::class, 'register']);
    Route::post('/login',          [AuthController::class, 'login']);
    Route::post('/forgot-password', [\App\Http\Controllers\Auth\ForgotPasswordController::class, 'send']);
    Route::post('/reset-password',  [\App\Http\Controllers\Auth\ResetPasswordController::class, 'reset']);

});

// Session probe + logout: called on every reload of an authenticated tab, so
// they live under the standard API limiter — the 5/min brute-force limiter
// above logged users out whenever /auth/me was refreshed a few times a minute.
Route::prefix('auth')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);
    Route::get('/me/access-manifest', \App\Http\Controllers\AccessManifestController::class);
    // Phase 2D — the caller's own EFFECTIVE PAGE ACCESS (read-only).
    // PAGE ACCESS != BUSINESS AUTHORIZATION: deliberately separate from
    // /me/access-manifest above, which returns effective PERMISSIONS. Open to
    // every authenticated user because everyone has their own page access.
    // Phase 2D does NOT switch the frontend — dashboardAccess.ts, the sidebar
    // and DashboardAccessGuard remain authoritative until a later phase.
    Route::get('/me/page-access', \App\Http\Controllers\EffectivePageAccessController::class);
});

// ─── Public: Certificate verification ────────────────────────────────────────
Route::get('/certificates/verify/{code}', [CertificateVerificationController::class, 'verify'])
    ->middleware('throttle:20,1');

// Legacy compat: GET /api/certificates → admin or student list (avoid 404 from old clients)
Route::middleware('auth:sanctum')->get('/certificates', [\App\Http\Controllers\Admin\CertificateController::class, 'legacyList']);

// ─── Public: Knowledge base (published + public articles) ────────────────────
Route::get('/knowledge',        [KnowledgeController::class, 'index']);
Route::get('/knowledge/{slug}', [KnowledgeController::class, 'show']);

// ─── Global search (auth optional — results filtered by role) ─────────────────
Route::get('/search', SearchController::class)->middleware('throttle:api');

// ─── Public: Team (EMC V3 website team page — no auth required) ──────────────
Route::get('/team', [\App\Http\Controllers\TeamController::class, 'index'])->middleware('throttle:api');

// ─── Public: Expert & Instructor Application ──────────────────────────────────
Route::post('/expert-applications', [\App\Http\Controllers\Public\PublicExpertApplicationController::class, 'store'])->middleware('throttle:api');

// ─── Student LMS (auth:sanctum + role:student) ─────────────────────────────────
Route::prefix('student')->middleware(['auth:sanctum', 'role:student'])->group(function () {
    Route::get('/dashboard',     StudentDashboardController::class);
    Route::get('/sessions',      [StudentSessionController::class, 'index']);
    Route::get('/sessions/calendar', [StudentSessionController::class, 'calendar']);
    Route::get('/sessions/{session}', [StudentSessionController::class, 'show']);
    Route::get('/materials',     [StudentMaterialController::class, 'index']);
    Route::get('/assignments',              [StudentAssignmentController::class, 'index']);
    Route::get('/assignments/{assignment}', [StudentAssignmentController::class, 'show']);
    Route::post('/assignments/{assignment}/submit', [StudentAssignmentController::class, 'submit']);
    Route::get('/assignments/{assignment}/history', [StudentAssignmentController::class, 'history']);
    Route::post('/lessons/{lesson}/complete', [StudentLessonController::class, 'complete']);
    Route::get('/progress',      [StudentProgressController::class, 'index']);
    Route::get('/attendance',           [\App\Http\Controllers\Student\StudentAttendanceController::class, 'index']);
    Route::get('/attendance/summary',   [\App\Http\Controllers\Student\StudentAttendanceController::class, 'summary']);
    Route::get('/attendance/{session}', [\App\Http\Controllers\Student\StudentAttendanceController::class, 'show']);
    Route::get('/classes/{group}/announcements', [\App\Http\Controllers\Student\StudentClassAnnouncementController::class, 'index']);
    Route::post('/evaluations',  [StudentEvaluationController::class, 'store']);
    Route::get('/reviews',       [StudentEvaluationController::class, 'index']);
    Route::get('/certificates',                       [StudentCertificateController::class, 'index']);
    Route::get('/certificates/{certificate}',         [StudentCertificateController::class, 'show']);
    Route::get('/certificates/{certificate}/download',[StudentCertificateController::class, 'download'])->middleware('throttle:20,1');

    // Enrolled courses, registration history, discovery, and completed exams
    Route::get('/registrations',    [StudentRegistrationsController::class, 'index']);
    Route::get('/courses',          [StudentRegistrationsController::class, 'courses']);
    Route::get('/available-courses',[StudentRegistrationsController::class, 'availableCourses']);
    Route::get('/upcoming-sessions',[StudentSessionController::class, 'index']);
    Route::post('/sessions/{session}/open-link', [StudentSessionController::class, 'openLink']);
    Route::get('/exams',            [StudentRegistrationsController::class, 'exams']);

    // Advanced LMS
    Route::get('/learning',                    [\App\Http\Controllers\Student\StudentLearningController::class, 'index']);
    Route::get('/lessons/{lesson}',            [\App\Http\Controllers\Student\StudentLearningController::class, 'lesson']);

    // الاختبارات القصيرة — quizzes count toward the course final grade; see StudentQuizController.
    Route::get('/courses/{course}/quizzes',       [\App\Http\Controllers\Student\StudentQuizController::class, 'index']);
    Route::get('/courses/{course}/final-grade',   [\App\Http\Controllers\Student\StudentQuizController::class, 'finalGrade']);
    Route::get('/quizzes/{quiz}',                 [\App\Http\Controllers\Student\StudentQuizController::class, 'show']);
    Route::post('/quizzes/{quiz}/submit',      [\App\Http\Controllers\Student\StudentQuizController::class, 'submit']);

    // Placement-gated course content — blocked until placement complete
    Route::middleware('placement.required')->group(function () {
        Route::get('/courses/{courseId}/modules', [\App\Http\Controllers\Student\StudentLearningController::class, 'modules']);
        Route::get('/courses/{courseId}/learn',   [\App\Http\Controllers\Student\StudentLearnController::class, 'show']);
        Route::get('/learn/{courseId}',           [\App\Http\Controllers\Student\StudentLearnController::class, 'show']);
    });

    // ── Course notes (private per student per course) ─────────────────────────
    Route::get('/courses/{courseId}/notes', [\App\Http\Controllers\Student\StudentNoteController::class, 'show']);
    Route::put('/courses/{courseId}/notes', [\App\Http\Controllers\Student\StudentNoteController::class, 'upsert']);

    // ── Placement test ───────────────────────────────────────────────────────
    Route::get( '/courses/{course}/placement-test/status', [StudentPlacementTestController::class, 'status']);
    Route::get( '/courses/{course}/placement-test/result', [StudentPlacementTestController::class, 'result']);
    Route::post('/courses/{course}/placement-test/start',  [StudentPlacementTestController::class, 'start']);
    Route::get( '/courses/{course}/placement-test',        [StudentPlacementTestController::class, 'show']);
    Route::post('/courses/{course}/placement-test/submit', [StudentPlacementTestController::class, 'submit']);
    Route::post('/courses/{course}/placement-test/violations', [StudentPlacementTestController::class, 'logViolation'])
        ->middleware('throttle:30,1'); // exam-integrity events, but must not be abusable as a flood vector

    // ── Oral assessment ──────────────────────────────────────────────────────
    Route::get( '/courses/{course}/oral-assessment/slots', [StudentOralAssessmentController::class, 'slots']);
    Route::post('/courses/{course}/oral-assessment/book',  [StudentOralAssessmentController::class, 'book']);

    // ── Learning Paths (student) ─────────────────────────────────────────────
    Route::get('/learning-paths',      [\App\Http\Controllers\Student\StudentLearningPathController::class, 'index']);
    Route::get('/learning-paths/{id}', [\App\Http\Controllers\Student\StudentLearningPathController::class, 'show']);

    // Orders & invoices
    Route::get('/orders',                              [StudentOrderController::class, 'index']);
    Route::get('/orders/{order}',                      [StudentOrderController::class, 'show']);
    Route::get('/invoices/{invoice}/download',         [StudentOrderController::class, 'downloadInvoice']);
});

// ─── Generic dashboard alias: GET /api/dashboard ─────────────────────────────
// Serves the student dashboard. Authenticated students only.
// The student frontend calls this URL; role check is enforced via auth:sanctum + role:student.
Route::middleware(['auth:sanctum', 'role:student'])
    ->get('/dashboard', \App\Http\Controllers\Student\StudentDashboardController::class);

// ─── Protected material delivery (auth:sanctum, policy-guarded) ──────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/materials/{material}/download',
        [\App\Http\Controllers\Student\MaterialDownloadController::class, 'download']
    )->name('materials.download');

    Route::get('/materials/{material}/preview',
        [\App\Http\Controllers\Student\MaterialDownloadController::class, 'preview']
    )->name('materials.preview');

    Route::get('/materials/{material}/stream',
        [\App\Http\Controllers\Student\MaterialDownloadController::class, 'stream']
    )->name('materials.stream');

    Route::get('/materials/{material}/thumbnail',
        [\App\Http\Controllers\Student\MaterialDownloadController::class, 'thumbnail']
    )->name('materials.thumbnail');

    Route::get('/materials/{material}/medium',
        [\App\Http\Controllers\Student\MaterialDownloadController::class, 'medium']
    )->name('materials.medium');

    Route::get('/student/materials/{material}',
        [\App\Http\Controllers\Student\StudentMaterialController::class, 'show']
    )->name('student.materials.show');
});

// ─── Protected assignment submission download (auth:sanctum, access checked inside) ──
Route::middleware('auth:sanctum')->get(
    '/submissions/{submission}/download',
    [\App\Http\Controllers\SubmissionDownloadController::class, 'download']
)->name('submissions.download');

// ─── HR Staffing Requests (طلبات الاحتياج الوظيفي بين الإدارات و HR والإدارة العليا) ────────
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::post('/hr-requests',                        [\App\Http\Controllers\Hr\HrRequestController::class, 'store']);
    Route::get('/hr-requests/my',                      [\App\Http\Controllers\Hr\HrRequestController::class, 'myRequests']);
    Route::get('/hr-requests/incoming',                [\App\Http\Controllers\Hr\HrRequestController::class, 'incomingRequests']);
    Route::patch('/hr-requests/{hrRequest}/status',    [\App\Http\Controllers\Hr\HrRequestController::class, 'updateStatus']);
});

// ─── Instructor submission file (inline preview or attachment, JSON errors) ────
Route::get(
    '/instructor/submissions/{submission}/file',
    [\App\Http\Controllers\SubmissionDownloadController::class, 'instructorFile']
)->name('instructor.submissions.file');

// ─── Realtime broadcasting auth (Ticket 8 completion pass) ─────────────────────
// The framework auto-registers GET/POST /broadcasting/auth under the "web"
// (session-cookie) middleware group via bootstrap/app.php's `channels:`
// routing entry — useless for this Bearer-token-only SPA/API. This exposes
// the same channel-authorization logic (routes/channels.php) at
// /api/broadcasting/auth under auth:sanctum instead, which the frontend
// Echo client is configured to call with its Authorization header.
\Illuminate\Support\Facades\Broadcast::routes(['middleware' => ['auth:sanctum'], 'guards' => ['sanctum']]);

// ─── Notifications (auth:sanctum, any role) ───────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications',                   [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count',      [NotificationController::class, 'unreadCount']);
    Route::put('/notifications/read-all',          [NotificationController::class, 'markAllRead']);
    Route::put('/notifications/{id}/read',         [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{id}',           [NotificationController::class, 'delete']);
    Route::post('/notifications/bulk',             [NotificationController::class, 'bulk']);
    Route::put('/notifications/{id}/pin',          [NotificationController::class, 'pin']);
    Route::put('/notifications/{id}/unpin',        [NotificationController::class, 'unpin']);
    Route::put('/notifications/{id}/archive',      [NotificationController::class, 'archive']);
    Route::put('/notifications/{id}/unarchive',    [NotificationController::class, 'unarchive']);

    // ─── Push subscriptions (Ticket 8 completion pass) ─────────────────────────
    Route::get('/push-subscriptions',    [\App\Http\Controllers\PushSubscriptionController::class, 'index']);
    Route::post('/push-subscriptions',   [\App\Http\Controllers\PushSubscriptionController::class, 'store']);
    Route::delete('/push-subscriptions', [\App\Http\Controllers\PushSubscriptionController::class, 'destroy']);

    // ─── Notification quiet hours (Ticket 8 completion pass) ───────────────────
    Route::get('/notifications/quiet-hours', [\App\Http\Controllers\NotificationPreferenceController::class, 'quietHours']);
    Route::put('/notifications/quiet-hours', [\App\Http\Controllers\NotificationPreferenceController::class, 'updateQuietHours']);

    // Documents (auth:sanctum, any role; visibility enforced in controller)
    Route::get('/documents',  [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');

    // AI Conversations (Phase 5 — enhanced Phase 8)
    Route::prefix('ai')->middleware('throttle:ai')->group(function () {
        Route::get('/conversations',                          [AiConversationController::class, 'index']);
        Route::post('/conversations',                         [AiConversationController::class, 'store']);
        Route::get('/conversations/{conversation}',           [AiConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/messages', [AiConversationController::class, 'sendMessage']);
        Route::delete('/conversations/{conversation}',        [AiConversationController::class, 'destroy']);

        // AI Meeting intelligence
        Route::post('/meetings/{meeting}/summarize',    [AiMeetingController::class, 'summarize']);
        Route::get('/meetings/{meeting}/summary',       [AiMeetingController::class, 'getSummary']);
        Route::post('/meetings/{meeting}/action-items', [AiMeetingController::class, 'actionItems']);

        // AI Recommendations
        Route::get('/recommendations',              [AiRecommendationController::class, 'index']);
        Route::post('/recommendations/refresh',     [AiRecommendationController::class, 'refresh']);
        Route::patch('/recommendations/{recommendation}/dismiss', [AiRecommendationController::class, 'dismiss']);

        // AI Semantic Search
        Route::post('/search', [AiSemanticSearchController::class, 'search']);

        // AI Content Generation
        Route::post('/generate/course-outline',  [AiContentGenerationController::class, 'courseOutline']);
        Route::post('/generate/workshop',        [AiContentGenerationController::class, 'workshop']);
        Route::post('/generate/quiz',            [AiContentGenerationController::class, 'quiz']);
        Route::post('/generate/marketing-copy',  [AiContentGenerationController::class, 'marketingCopy']);
        Route::post('/generate/report-summary',  [AiContentGenerationController::class, 'reportSummary']);
        Route::post('/generate/learning-path',   [AiContentGenerationController::class, 'learningPath']);
    });
});

// ─── Partner Portal (auth:sanctum + role:partner,admin) ──────────────────────
Route::prefix('partner')->middleware(['auth:sanctum', 'role:partner,admin'])->group(function () {
    Route::get('/dashboard', PartnerDashboardController::class);
    Route::get('/programs',  [PartnerProgramController::class, 'index']);
    Route::get('/reports',   [PartnerReportController::class, 'index']);
    Route::get('/documents', [PartnerDocumentController::class, 'index']);
    Route::post('/documents',[PartnerDocumentController::class, 'store']);
    Route::get('/documents/{document}/download', [PartnerDocumentController::class, 'download'])->name('partner.documents.download');
});

// ─── Instructor LMS portal (auth:sanctum + role:instructor,admin) ───────────
Route::prefix('instructor')->middleware(['auth:sanctum', 'role:instructor,admin'])->group(function () {
    Route::get('/dashboard',   InstructorDashboardController::class);
    Route::get('/sessions',    [InstructorSessionController::class, 'index']);
    Route::get('/courses',     [InstructorCourseController::class, 'index']);
    Route::get('/workshops',   [InstructorWorkshopController::class, 'index']);
    Route::get('/students',    [InstructorStudentController::class, 'index']);
    Route::get('/attendance/dashboard',                                 [SessionAttendanceController::class,    'dashboard']);
    Route::get('/attendance/settings',                                  [\App\Http\Controllers\Admin\AttendanceSettingsController::class, 'show']);
    Route::get('/attendance/reports',                                   [SessionAttendanceController::class,    'reports']);
    Route::get('/attendance/export',                                    [SessionAttendanceController::class,    'export']);
    Route::get('/attendance/export-pdf',                                [SessionAttendanceController::class,    'exportPdf']);
    Route::get('/attendance/export-excel',                              [SessionAttendanceController::class,    'exportExcel']);
    Route::get('/attendance/{session}',            [SessionAttendanceController::class,    'show']);
    Route::get('/attendance',                                           [SessionAttendanceController::class,    'index']);
    Route::put('/attendance/{session}',                                 [SessionAttendanceController::class,    'update']);
    Route::get('/assignments',                                          [InstructorAssignmentController::class, 'index']);
    Route::get('/assignments/dashboard',                                [InstructorAssignmentController::class, 'dashboard']);
    Route::get('/assignments/missing-submissions',                      [InstructorAssignmentController::class, 'missingSubmissions']);
    Route::get('/submissions',                                          [InstructorSubmissionController::class, 'index']);
    Route::put('/submissions/bulk-review',                              [InstructorSubmissionController::class, 'bulkReview']);
    Route::get('/submissions/{submission}',                             [InstructorSubmissionController::class, 'show']);
    Route::get('/submissions/{submission}/history',                     [InstructorSubmissionController::class, 'history']);
    Route::put('/submissions/{submission}/review',                      [InstructorSubmissionController::class, 'review']);
    Route::patch('/submissions/{submission}/review',                    [InstructorSubmissionController::class, 'review']);
    Route::patch('/submissions/{submission}/grade',                     [InstructorSubmissionController::class, 'review']);
    Route::get('/assignments/{assignment}/submissions',                  [InstructorSubmissionController::class, 'forAssignment']);
    Route::get('/notifications', [NotificationController::class, 'index']);

    // ── Placement: per-course student list + oral assessment management ───────
    Route::get( '/courses/{course}/placement-students',       [InstructorPlacementController::class, 'students']);
    Route::get( '/placement-tests',                                     [InstructorPlacementController::class, 'placementTests']);
    Route::get( '/placement-tests/{attempt}/answers',                  [InstructorPlacementController::class, 'answers']);

    // ── Quizzes — الاختبارات القصيرة (unlimited per course; final exam reuses the same engine) ──
    Route::get(   '/courses/{course}/quizzes',                          [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'index']);
    Route::post(  '/courses/{course}/quizzes',                          [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'store']);
    Route::get(   '/quizzes/{quiz}',                                    [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'show']);
    Route::patch( '/quizzes/{quiz}',                                    [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'update']);
    Route::delete('/quizzes/{quiz}',                                    [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'destroy']);
    Route::post(  '/quizzes/{quiz}/publish',                            [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'publish']);
    Route::post(  '/quizzes/{quiz}/schedule',                           [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'schedule']);
    Route::post(  '/quizzes/{quiz}/close',                              [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'close']);
    Route::post(  '/quizzes/{quiz}/reopen',                             [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'reopen']);
    Route::post(  '/quizzes/{quiz}/archive',                            [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'archive']);
    Route::post(  '/quizzes/{quiz}/duplicate',                          [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'duplicate']);
    Route::post(  '/quizzes/{quiz}/questions',                          [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'storeQuestion']);
    Route::patch( '/quizzes/{quiz}/questions/{question}',                [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'updateQuestion']);
    Route::delete('/quizzes/{quiz}/questions/{question}',                [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'destroyQuestion']);
    Route::get(   '/courses/{course}/assessment-settings',              [\App\Http\Controllers\Instructor\InstructorCourseAssessmentController::class, 'show']);
    Route::put(   '/courses/{course}/assessment-settings',              [\App\Http\Controllers\Instructor\InstructorCourseAssessmentController::class, 'update']);
    Route::get(   '/courses/{course}/students/{user}/quiz-breakdown',    [\App\Http\Controllers\Instructor\InstructorQuizController::class, 'studentBreakdown']);

    // ── Class / group management ──────────────────────────────────────────────
    Route::get(   '/classes',                                           [\App\Http\Controllers\Instructor\InstructorClassController::class, 'index']);
    Route::post(  '/classes',                                           [\App\Http\Controllers\Instructor\InstructorClassController::class, 'store']);
    Route::patch( '/classes/{group}',                                   [\App\Http\Controllers\Instructor\InstructorClassController::class, 'update']);
    Route::delete('/classes/{group}',                                   [\App\Http\Controllers\Instructor\InstructorClassController::class, 'destroy']);
    Route::get(   '/classes/{group}',                                   [\App\Http\Controllers\Instructor\InstructorClassController::class, 'show']);
    Route::get(   '/classes/{group}/students',                          [\App\Http\Controllers\Instructor\InstructorClassController::class, 'groupStudents']);
    Route::post(  '/classes/{group}/students',                          [\App\Http\Controllers\Instructor\InstructorClassController::class, 'addStudent']);
    Route::delete('/classes/{group}/students/{userId}',                 [\App\Http\Controllers\Instructor\InstructorClassController::class, 'removeStudent']);
    Route::get(   '/sessions/calendar',                                 [\App\Http\Controllers\Instructor\InstructorClassController::class, 'calendar']);
    Route::get(   '/classes/{group}/sessions',                          [\App\Http\Controllers\Instructor\InstructorClassController::class, 'groupSessions']);
    Route::post(  '/classes/{group}/sessions',                          [\App\Http\Controllers\Instructor\InstructorClassController::class, 'storeSession']);
    Route::get(   '/classes/{group}/sessions/{session}',                 [\App\Http\Controllers\Instructor\InstructorClassController::class, 'showSession']);
    Route::patch( '/classes/{group}/sessions/{session}',                 [\App\Http\Controllers\Instructor\InstructorClassController::class, 'updateSession']);
    Route::post(  '/classes/{group}/sessions/{session}/transition',      [\App\Http\Controllers\Instructor\InstructorClassController::class, 'transitionSession']);
    Route::delete('/classes/{group}/sessions/{session}',                 [\App\Http\Controllers\Instructor\InstructorClassController::class, 'destroySession']);
    Route::post(  '/classes/{group}/sessions/generation-preview',        [\App\Http\Controllers\Instructor\InstructorClassController::class, 'previewSessionGeneration']);
    Route::post(  '/classes/{group}/sessions/generate',                  [\App\Http\Controllers\Instructor\InstructorClassController::class, 'generateSessions']);
    Route::get(   '/classes/{group}/attendance',                        [\App\Http\Controllers\Instructor\InstructorClassController::class, 'groupAttendance']);
    Route::get(   '/classes/{group}/materials',                         [\App\Http\Controllers\Instructor\InstructorClassController::class, 'groupMaterials']);
    Route::get(   '/classes/{group}/curriculum/analytics',              [\App\Http\Controllers\Instructor\InstructorClassController::class, 'curriculumAnalytics']);
    Route::get(   '/classes/{group}/assignments',                       [\App\Http\Controllers\Instructor\InstructorClassController::class, 'groupAssignments']);
    Route::get(   '/classes/{group}/announcements',                     [\App\Http\Controllers\Instructor\InstructorClassController::class, 'groupAnnouncements']);
    Route::post(  '/classes/{group}/announcements',                     [\App\Http\Controllers\Instructor\InstructorClassController::class, 'storeAnnouncement']);
    Route::patch( '/classes/{group}/announcements/{announcement}/publish', [\App\Http\Controllers\Instructor\InstructorClassController::class, 'publishAnnouncement']);
    Route::delete('/classes/{group}/announcements/{announcement}',      [\App\Http\Controllers\Instructor\InstructorClassController::class, 'archiveAnnouncement']);
    Route::get(   '/courses/{course}/classes',                          [\App\Http\Controllers\Instructor\InstructorClassController::class, 'courseGroups']);
    Route::get(   '/courses/{course}/students',                         [\App\Http\Controllers\Instructor\InstructorClassController::class, 'courseStudents']);
    Route::get(   '/courses/{course}/class-assignment/students',        [\App\Http\Controllers\Instructor\InstructorClassController::class, 'assignmentStudents']);
    Route::get( '/oral-assessments',                          [InstructorPlacementController::class, 'assessments']);
    Route::patch('/oral-assessments/{booking}/complete',      [InstructorPlacementController::class, 'complete']);

    // ── Availability (oral assessment slots CRUD) ─────────────────────────────
    Route::get(   '/availability',             [InstructorAvailabilityController::class, 'index']);
    Route::post(  '/availability/bulk',        [InstructorAvailabilityController::class, 'bulk']);
    Route::post(  '/availability',             [InstructorAvailabilityController::class, 'store']);
    Route::delete('/availability/{slot}',      [InstructorAvailabilityController::class, 'destroy']);

    // ── Oral-interview booking detail & post-booking actions ──────────────────
    Route::get(  '/oral-bookings/{booking}',              [InstructorOralBookingController::class, 'show']);
    Route::patch('/oral-bookings/{booking}/status',        [InstructorOralBookingController::class, 'updateStatus']);
    Route::patch('/oral-bookings/{booking}/meeting-link',  [InstructorOralBookingController::class, 'updateMeetingLink']);
    Route::post( '/oral-bookings/{booking}/message',        [InstructorOralBookingController::class, 'sendMessage']);
    Route::post( '/oral-bookings/{booking}/reschedule',      [InstructorOralBookingController::class, 'reschedule']);

    // ── Course content: individual GET indexes ───────────────────────────────
    Route::get('/courses/{course}/content',                              [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'content']);
    Route::get('/courses/{course}/modules',                              [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'indexModules']);
    Route::get('/courses/{course}/sessions',                             [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'indexSessions']);
    Route::get('/courses/{course}/materials',                            [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'indexMaterials']);
    Route::get('/courses/{course}/assignments',                          [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'indexAssignments']);

    // ── Course content: nested CRUD (POST /instructor/courses/{course}/…) ────────
    Route::post('/courses/{course}/modules',                             [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'storeModule']);
    Route::post('/courses/{course}/modules/reorder',                     [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'reorderModules']);
    Route::post('/courses/{course}/modules/{module}/lessons/reorder',    [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'reorderLessons']);
    Route::put('/courses/{course}/modules/{module}',                     [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateModule']);
    Route::patch('/courses/{course}/modules/{module}',                   [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateModule']);
    Route::delete('/courses/{course}/modules/{module}',                  [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'destroyModule']);
    Route::post('/courses/{course}/sessions',                            [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'storeCourseSession']);
    Route::put('/courses/{course}/sessions/{courseSession}',             [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateCourseSession']);
    Route::patch('/courses/{course}/sessions/{courseSession}',           [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateCourseSession']);
    Route::delete('/courses/{course}/sessions/{courseSession}',          [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'destroyCourseSession']);
    Route::post('/courses/{course}/materials',                           [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'storeMaterial']);
    Route::put('/courses/{course}/materials/{material}',                 [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateMaterial']);
    Route::patch('/courses/{course}/materials/{material}',               [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateMaterial']);
    Route::delete('/courses/{course}/materials/{material}',              [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'destroyMaterial']);
    Route::post('/courses/{course}/assignments',                         [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'storeAssignment']);
    Route::put('/courses/{course}/assignments/{courseAssignment}',       [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateAssignment']);
    Route::patch('/courses/{course}/assignments/{courseAssignment}',     [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateAssignment']);
    Route::delete('/courses/{course}/assignments/{courseAssignment}',    [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'destroyAssignment']);
    Route::post('/courses/{course}/lessons',                             [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'storeLesson']);
    Route::put('/courses/{course}/lessons/{lesson}',                     [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateLesson']);
    Route::patch('/courses/{course}/lessons/{lesson}',                   [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateLesson']);
    Route::delete('/courses/{course}/lessons/{lesson}',                  [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'destroyLesson']);

    // ── Learning Paths (instructor — read-only; course structure is admin-only) ─
    Route::get('/learning-paths',                          [\App\Http\Controllers\Instructor\InstructorLearningPathController::class, 'index']);
    Route::get('/learning-paths/{id}',                     [\App\Http\Controllers\Instructor\InstructorLearningPathController::class, 'show']);
    Route::get('/learning-paths/{id}/students',            [\App\Http\Controllers\Instructor\InstructorLearningPathController::class, 'students']);
    Route::get('/learning-paths/{id}/sessions',            [\App\Http\Controllers\Instructor\InstructorLearningPathController::class, 'sessions']);
    Route::get('/learning-paths/{id}/assignments',         [\App\Http\Controllers\Instructor\InstructorLearningPathController::class, 'assignments']);
    Route::get('/learning-paths/{id}/materials',           [\App\Http\Controllers\Instructor\InstructorLearningPathController::class, 'materials']);

    // ── Course content: flat routes (PUT /instructor/modules|sessions|…/{id}) ──
    Route::put('/modules/{module}',                                      [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateModule']);
    Route::patch('/modules/{module}',                                    [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateModule']);
    Route::delete('/modules/{module}',                                   [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'destroyModule']);
    Route::put('/sessions/{courseSession}',                              [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateCourseSession']);
    Route::patch('/sessions/{courseSession}',                            [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateCourseSession']);
    Route::delete('/sessions/{courseSession}',                           [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'destroyCourseSession']);
    Route::put('/materials/{material}',                                  [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateMaterial']);
    Route::patch('/materials/{material}',                                [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateMaterial']);
    Route::delete('/materials/{material}',                               [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'destroyMaterial']);
    Route::put('/assignments/{courseAssignment}',                        [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateAssignment']);
    Route::patch('/assignments/{courseAssignment}',                      [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateAssignment']);
    Route::delete('/assignments/{courseAssignment}',                     [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'destroyAssignment']);
    Route::put('/lessons/{lesson}',                                      [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateLesson']);
    Route::patch('/lessons/{lesson}',                                    [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'updateLesson']);
    Route::delete('/lessons/{lesson}',                                   [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'destroyLesson']);
});

// ─── Public: Tracks (LMS) ────────────────────────────────────────────────────
Route::get('/tracks',       [TrackController::class, 'index']);
Route::get('/tracks/{slug}', [TrackController::class, 'show']);

// ─── Public: Workshops ───────────────────────────────────────────────────────
// optional.sanctum: show enrollment_status when user is logged in
Route::middleware('optional.sanctum')->group(function () {
    Route::get('/workshops',        [\App\Http\Controllers\Public\WorkshopController::class, 'index']);
    Route::get('/workshops/{slug}', [\App\Http\Controllers\Public\WorkshopController::class, 'show']);
});

// ─── Public: Programs aggregation (courses + workshops + learning paths) ────────
Route::middleware('optional.sanctum')->group(function () {
    Route::get('/programs', [\App\Http\Controllers\Public\PublicProgramsController::class, 'index']);
});

// ─── Public: Courses ─────────────────────────────────────────────────────────
Route::get('/courses',        [CourseController::class, 'index']);
Route::get('/courses/{slug}', [CourseController::class, 'show']);

// ─── Public: Instructors ─────────────────────────────────────────────────────
Route::get('/instructors',              [InstructorController::class, 'index']);
Route::get('/instructors/slug/{slug}', [InstructorController::class, 'showBySlug']);
Route::get('/instructors/{identifier}', [InstructorController::class, 'show'])
    ->where('identifier', '[^/]+');

// ─── Course Registration (throttle:registration — 5 req/min per IP) ──────────
// optional.sanctum: Bearer token links registration to user_id when logged in
Route::post('/register', [RegistrationController::class, 'store'])
    ->middleware(['throttle:registration', 'optional.sanctum']);

Route::post('/courses/{course}/register', [RegistrationController::class, 'storeForCourse'])
    ->middleware(['throttle:registration', 'optional.sanctum']);

// Paid course checkout — student only
Route::post('/courses/{course}/checkout', [CheckoutController::class, 'checkout'])
    ->middleware(['auth:sanctum', 'role:student', 'throttle:payments']);

// Coupon preview — validates and previews pricing only, never consumes usage.
// throttle:payments (10/min/user) reused to blunt brute-force code enumeration.
Route::post('/courses/{course}/coupon/validate', [\App\Http\Controllers\Student\CouponController::class, 'validate'])
    ->middleware(['auth:sanctum', 'role:student', 'throttle:payments']);

// ─── Workshop Request Submission (throttle:workshop — 5 req/min per IP) ──────
Route::post('/workshop-requests', [WorkshopRequestController::class, 'store'])
    ->middleware('throttle:workshop');

// ─── Payment Webhooks (unauthenticated — called by Stripe/PayPal servers) ─────
// No throttle — webhook retry logic must not be blocked
Route::post('/payments/stripe/webhook', [PaymentController::class, 'stripeWebhook'])->name('payments.stripe.webhook');
Route::post('/payments/paypal/webhook', [PaymentController::class, 'paypalWebhook'])->name('payments.paypal.webhook');

// New order-based Stripe webhook (handles CheckoutController sessions)
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])->name('webhooks.stripe');

// Fake payment endpoints — FakePaymentEnvironment enforced in controller
Route::post('/payments/fake/confirm/{payment}', [PaymentController::class, 'confirmFakePayment'])->name('payments.fake.confirm');
Route::post('/payments/fake/fail/{payment}',    [PaymentController::class, 'failFakePayment'])->name('payments.fake.fail');

// ─── Members: GET — all authenticated users can view the team directory ──────
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    Route::get('members',          [AdminMemberController::class, 'index']);
    Route::get('members/{member}', [AdminMemberController::class, 'show']);
});

// ─── Admin registrations — admin + programs_manager ──────────────────────────
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,programs_manager'])->prefix('admin')->group(function () {
    Route::get('/registrations', [AdminController::class, 'registrations']);
    Route::get('/registrations/{registration}', [AdminController::class, 'showRegistration']);
    Route::put('/registrations/{registration}/status', [AdminController::class, 'updateRegistrationStatus']);
    Route::patch('/registrations/{registration}/status', [AdminController::class, 'updateRegistrationStatus']);
    Route::post('/registrations/{registration}/create-account', [AdminController::class, 'createAccountForRegistration']);
});

// ─── Admin: auth:sanctum + role:admin,super_admin,tech_admin ─────────────────
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin'])->group(function () {

    Route::get('/workshop-requests', [WorkshopRequestController::class, 'index']);

    Route::prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);

        Route::get('/payments',    [AdminPaymentController::class, 'index']);
        Route::get('/payments/{payment}', [AdminPaymentController::class, 'show']);

        // Orders (Stripe-based paid course flow)
        Route::get('/orders',                              [AdminOrderController::class, 'index']);
        Route::get('/orders/{order}',                      [AdminOrderController::class, 'show']);
        Route::get('/invoices',                            [AdminOrderController::class, 'invoices']);
        Route::get('/invoices/{invoice}/download',         [AdminOrderController::class, 'downloadInvoice']);

        Route::get('/tracks',            [AdminTrackController::class, 'index']);
        Route::post('/tracks',           [AdminTrackController::class, 'store']);
        Route::get('/tracks/{track}',    [AdminTrackController::class, 'show']);
        Route::put('/tracks/{track}',    [AdminTrackController::class, 'update']);
        Route::delete('/tracks/{track}', [AdminTrackController::class, 'destroy']);

        // LMS Phase 2
        Route::get('/sessions', [AdminLmsSessionController::class, 'index']);
        Route::post('/sessions', [AdminLmsSessionController::class, 'store']);
        Route::get('/sessions/{session}', [AdminLmsSessionController::class, 'show']);
        Route::put('/sessions/{session}', [AdminLmsSessionController::class, 'update']);
        Route::delete('/sessions/{session}', [AdminLmsSessionController::class, 'destroy']);

        Route::get('/assignments', [AdminLmsAssignmentController::class, 'index']);
        Route::post('/assignments', [AdminLmsAssignmentController::class, 'store']);
        Route::get('/assignments/{courseAssignment}', [AdminLmsAssignmentController::class, 'show']);
        Route::put('/assignments/{courseAssignment}', [AdminLmsAssignmentController::class, 'update']);
        Route::delete('/assignments/{courseAssignment}', [AdminLmsAssignmentController::class, 'destroy']);

        Route::get('/materials/stats', [AdminLmsMaterialController::class, 'stats']);
        Route::get('/materials', [AdminLmsMaterialController::class, 'index']);
        Route::post('/materials', [AdminLmsMaterialController::class, 'store'])
            ->middleware('throttle:20,60'); // 20 uploads per hour
        Route::get('/materials/{material}', [AdminLmsMaterialController::class, 'show']);
        Route::put('/materials/{material}', [AdminLmsMaterialController::class, 'update']);
        Route::patch('/materials/{material}', [AdminLmsMaterialController::class, 'update']);
        Route::delete('/materials/{material}', [AdminLmsMaterialController::class, 'destroy']);

        Route::get('/attendance', [AdminLmsAttendanceController::class, 'index']);
        Route::put('/attendance/{session}', [SessionAttendanceController::class, 'update']);
        Route::get('/attendance-settings', [\App\Http\Controllers\Admin\AttendanceSettingsController::class, 'show']);
        Route::put('/attendance-settings', [\App\Http\Controllers\Admin\AttendanceSettingsController::class, 'update']);

        Route::get('/progress', [AdminLmsProgressController::class, 'index']);
        Route::get('/evaluations', [AdminLmsEvaluationController::class, 'index']);
    });
});

// ─── Content Management: auth:sanctum + role:admin,teacher ───────────────────
Route::middleware(['auth:sanctum', 'role:admin,teacher'])->group(function () {
    Route::post('/courses',     [CourseController::class, 'store']);
    Route::post('/instructors', [InstructorController::class, 'store']);
});

// ─── Public: Forms (any visitor can view active forms and submit) ─────────────
Route::middleware('throttle:api')->group(function () {
    Route::get('/forms/{slug}',        [PublicFormController::class, 'show']);
    Route::post('/forms/{slug}/submit', [PublicFormController::class, 'submit']);
});

// ─── Public: Partnership requests (throttle:workshop recycled — 5 req/min) ────
Route::post('/partnership-requests', [PublicPartnershipRequestController::class, 'store'])
    ->middleware('throttle:workshop');

// ─── Public: Volunteer requests (throttle:workshop — 5 req/min per IP) ────────
Route::post('/volunteer-requests', [PublicVolunteerRequestController::class, 'store'])
    ->middleware('throttle:workshop');

// ─── Public: AI Level Assessment — النتيجة + مكافأة 7% (كوبون حقيقي) ─────────
Route::post('/ai-assessment/results', [\App\Http\Controllers\Public\AiAssessmentController::class, 'store'])
    ->middleware('throttle:30,1');

// ─── Public: Visitor analytics ingest (sendBeacon، خلف موافقة المتصفح) ───────
Route::post('/analytics/events', [\App\Http\Controllers\Public\AnalyticsIngestController::class, 'store'])
    ->middleware('throttle:120,1');

// ─── Public: Consultant applications (نفس مُقيِّد التطوع) ─────────────────────
Route::post('/consultant-applications', [PublicConsultantApplicationController::class, 'store'])
    ->middleware('throttle:workshop');

// ─── Public: Ambassador applications ─────────────────────────────────────────
Route::post('/ambassador-applications', [PublicAmbassadorController::class, 'store'])
    ->middleware('throttle:workshop');
Route::post('/ambassador-applications/draft', [PublicAmbassadorController::class, 'saveDraft'])
    ->middleware('throttle:60,1');
Route::get('/ambassador-applications/draft/{token}', [PublicAmbassadorController::class, 'getDraft'])
    ->middleware('throttle:30,1');

// Signed CV download route removed — privacy policy: preview only, no download.

// Signed CV inline view
Route::get('admin/volunteer-requests/{volunteerRequest}/cv/view', [AdminVolunteerRequestController::class, 'viewCv'])
    ->middleware('signed')
    ->name('admin.volunteer-requests.cv.view');

// Signed consultant CV inline view (نفس سياسة سير المتطوعين: معاينة فقط)
Route::get('admin/consultant-applications/{consultantApplication}/cv/view', [AdminConsultantApplicationController::class, 'viewCv'])
    ->middleware('signed')
    ->name('admin.consultant-applications.cv.view');

// Ambassador application file routes (auth:sanctum — role check done in controller)
// Privacy policy: staff may preview applicant CV/student-ID/photo in-app but
// never download the raw file — downloadCv()/downloadStudentId() routes removed.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('admin/ambassador-applications/{ambassadorApplication}/cv/view',
        [AdminAmbassadorApplicationController::class, 'viewCv'])
        ->name('admin.ambassador.cv.view');
    Route::get('admin/ambassador-applications/{ambassadorApplication}/photo',
        [AdminAmbassadorApplicationController::class, 'viewPhoto'])
        ->name('admin.ambassador.photo.view');

    // Ambassador application file management (role check: super_admin, admin, hr_manager via controller)
    Route::prefix('admin/ambassador-applications/{ambassadorApplication}/files')
        ->name('admin.ambassador-applications.files.')
        ->group(function () {
            Route::get('/',                  [AdminAmbassadorApplicationFileController::class, 'index'])    ->name('index');
            Route::get('/{fileId}/preview',  [AdminAmbassadorApplicationFileController::class, 'preview'])  ->name('preview');
            Route::get('/{fileId}/thumbnail',[AdminAmbassadorApplicationFileController::class, 'thumbnail'])->name('thumbnail');
            Route::delete('/{fileId}',       [AdminAmbassadorApplicationFileController::class, 'destroy'])  ->name('destroy');
        });
});

// Admin Expert Applications — a talent/expert-recruitment intake ("Join Us
// as Trainer": trainer/consultant/mentor/evaluator applications), NOT an AI
// system feature. Historically nested under /admin/ai/* and granted to
// ai_manager for no evidenced reason; removed per the AI Department/AI
// Platform separation review. Permanent ownership (HR? Partnerships?
// Community?) is an open product decision — reverted to admin-tier access
// only in the meantime, not guessed at.
// Expert / trainer applications: the admin tier plus the AI department
// manager, who owns this intake (page: organizational_departments.ai_expert_applications).
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,ai_manager'])->prefix('admin/expert-applications')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\AdminExpertApplicationController::class, 'index']);
    Route::get('/{id}', [\App\Http\Controllers\Admin\AdminExpertApplicationController::class, 'show']);
    Route::put('/{id}/status', [\App\Http\Controllers\Admin\AdminExpertApplicationController::class, 'updateStatus']);
});

// ─── Public: Support tickets ─────────────────────────────────────────────────
Route::post('/support-tickets', [PublicSupportTicketController::class, 'store'])
    ->middleware('throttle:workshop');

// ─── HR + Admin: accepted volunteers list & volunteer conversion ──────────────
// Registered BEFORE the admin group so hr_manager is not blocked by the
// admin-only group that contains volunteers/{volunteer} wildcard below.
Route::middleware(['auth:sanctum', 'role:hr_manager,admin,super_admin,tech_admin'])->prefix('admin')->group(function () {
    Route::get('volunteers/accepted',                                        [AdminVolunteerRequestController::class, 'acceptedIndex']);
    Route::post('volunteer-requests/{volunteerRequest}/convert-to-member',   [AdminVolunteerRequestController::class, 'convertToMember']);
});

// Ambassador applications personal-data export center removed — privacy
// policy: no CSV/Excel export of applicant personal data.

// ── Resource Center (مركز الموارد) — read-only course/workshop/learning-path
// discovery for internal staff. Deliberately its own route group with a
// broad role set (never touches enrollment/creation, so no need to restrict
// to the narrower Phase 3 admin roles below). Keep in sync with
// frontend RESOURCE_CENTER_ROLES (+ legacy marketing/quality aliases). ──
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,executive_admin,programs_manager,instructor,hr_manager,finance_manager,marketing,marketing_manager,quality,quality_manager,support_agent,operations_manager,partnerships_manager,community_manager,volunteer'])
    ->prefix('admin/resource-center')
    ->group(function () {
        Route::get('courses',                          [\App\Http\Controllers\Admin\ResourceCenterController::class, 'index']);
        Route::get('favorites',                         [\App\Http\Controllers\Admin\ResourceCenterController::class, 'favorites']);
        Route::post('courses/{course}/favorite',        [\App\Http\Controllers\Admin\ResourceCenterController::class, 'toggleFavorite']);
        Route::get('export',                            [\App\Http\Controllers\Admin\ResourceCenterController::class, 'export']);
    });

// ─── Operations shared surface: يراها كل الفريق (قراءة) + كتابة مقيَّدة ───────
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,executive_admin,programs_manager,instructor,hr_manager,finance_manager,marketing,marketing_manager,quality,quality_manager,support_agent,operations_manager,partnerships_manager,community_manager,volunteer,department_manager,section_lead,ai_manager'])->prefix('operations')->group(function () {
    // لوحة التشغيل المرئية للجميع + أعضاء الإدارات
    Route::get('board',                              [OperationsBoardController::class, 'board']);
    Route::get('departments/my-access',              [OperationsBoardController::class, 'myDepartmentAccess']);
    Route::get('departments/{department}/members',   [OperationsBoardController::class, 'departmentMembers']);

    // تقارير ما بعد الاجتماع (محضر منظَّم + تقييم الحاضرين)
    Route::get('meeting-reports',                    [MeetingReportController::class, 'index']);
    Route::get('meeting-reports/{meetingReport}',    [MeetingReportController::class, 'show']);

    // تقارير الإثنين الأسبوعية لكل إدارة
    Route::get('weekly-reports',                     [WeeklyReportController::class, 'index']);
    Route::get('weekly-reports/due',                 [WeeklyReportController::class, 'due']);
    Route::get('weekly-reports/summary',             [WeeklyReportController::class, 'summary']);

    // نقاط أثر EMC — ملخصي وسجلي ولوحة الصدارة والسياسة
    Route::get('volunteer-points/policy',            [VolunteerPointsController::class, 'policy']);
    Route::get('volunteer-points/summary',           [VolunteerPointsController::class, 'mySummary']);
    Route::get('volunteer-points/my-awards',         [VolunteerPointsController::class, 'myAwards']);
    Route::get('volunteer-points/leaderboard',       [VolunteerPointsController::class, 'leaderboard']);

    // منح/استبدال نقاط الأثر وقائمة المستحقين: الصلاحية التجارية تُفحص داخل
    // المتحكم (members.recognize / members.manage + نطاق الإدارة) — لا قائمة أدوار.
    Route::get('volunteer-points/recipients',        [VolunteerPointsController::class, 'recipients']);
    Route::post('volunteer-points/awards',           [VolunteerPointsController::class, 'award']);
    Route::post('volunteer-points/redemptions',      [VolunteerPointsController::class, 'redeem']);

    // لوحة التشغيل: تحريك الحالة — العضو لمهامه والمدير للجميع (منطق المتحكم)
    Route::patch('board/tasks/{task}/status',        [\App\Http\Controllers\Admin\TaskController::class, 'updateStatus']);

    // كتابة مهام اللوحة: مدراء مخوَّلون (بلا تغيير عن السابق)
    Route::middleware('role:admin,super_admin,tech_admin,executive_admin,hr_manager,operations_manager,department_manager')->group(function () {
        Route::post('board/tasks',                   [\App\Http\Controllers\Admin\TaskController::class, 'store']);
        Route::post('tasks/{task}/lock',             [\App\Http\Controllers\Admin\TaskController::class, 'lock']);
        Route::post('tasks/{task}/unlock',           [\App\Http\Controllers\Admin\TaskController::class, 'unlock']);
    });

    // كتابة تقارير الاجتماعات/الأسبوعية: أي مدير إدارة حقيقي — التحقق الفعلي
    // من نطاق الإدارة (هل يقود هذه الإدارة تحديدًا) يتم داخل
    // DepartmentAccessService::canManageDepartment، وليس عبر قائمة الأدوار هنا.
    // القائمة هنا مجرد تصفية خشنة (استبعاد الأدوار غير التشغيلية)؛ التحقق
    // الفعلي من القيادة الحقيقية للإدارة يبقى في المتحكم.
    Route::middleware('role:admin,super_admin,tech_admin,executive_admin,department_manager,section_lead,hr_manager,finance_manager,marketing_manager,quality_manager,programs_manager,operations_manager,partnerships_manager,community_manager,support_agent,ai_manager')->group(function () {
        Route::post('meeting-reports',               [MeetingReportController::class, 'store']);
        Route::post('weekly-reports',                [WeeklyReportController::class, 'store']);
    });
});
// ─── Phase 3: Operations — auth:sanctum + role:admin ─────────────────────────
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,operations_manager'])->prefix('admin')->group(function () {

    // Departments
    Route::apiResource('departments', DepartmentController::class);
    // department-sections apiResource removed (Phase 0.2): Admin\DepartmentSectionController
    // had zero canonical department-scoping — role:operations_manager alone let any actor
    // create/update/delete ANY department's sections and set leader_id with no TeamProfile
    // check (confirmed exploitable). Zero consumers found (backend/frontend/tests/docs).
    // DepartmentStructureController (auth via DepartmentAccessService::canManageDepartment +
    // TeamProfile integrity) fully replaces this surface at /api/department/sections*.

    // استيراد الكتالوج من قالب الإكسل (معاينة ثم اعتماد)
    // Phase 2G.1C — narrowed to the admin tier. This group carries
    // operations_manager for the DEPARTMENT routes above; catalog import is a
    // platform-administration action that merely sat in the same group, and
    // operations_manager was never intended to reach it (no UI ever offered it).
    Route::middleware('role:admin')->group(function () {
        Route::post('catalog-import', [\App\Http\Controllers\Admin\CatalogImportController::class, 'store']);
    });

    // تحليلات الزوار — قراءة اللوحة
    Route::get('visitor-analytics/overview', [\App\Http\Controllers\Admin\VisitorAnalyticsController::class, 'overview']);
    Route::get('visitor-analytics/top',      [\App\Http\Controllers\Admin\VisitorAnalyticsController::class, 'top']);
    Route::get('visitor-analytics/errors',   [\App\Http\Controllers\Admin\VisitorAnalyticsController::class, 'errors']);

    // طلبات المستشارين — مراجعة واعتماد
    Route::get('consultant-applications',                                [AdminConsultantApplicationController::class, 'index']);
    Route::get('consultant-applications/{consultantApplication}',        [AdminConsultantApplicationController::class, 'show']);
    Route::put('consultant-applications/{consultantApplication}/status', [AdminConsultantApplicationController::class, 'updateStatus']);

    // Tasks
    Route::get('tasks/my',      [TaskController::class, 'myTasks']);
    Route::get('tasks/overdue', [TaskController::class, 'overdue']);
    Route::get('tasks/kanban',  [TaskController::class, 'kanban']);
    Route::apiResource('tasks', TaskController::class);
    Route::post('tasks/{task}/comments',               [TaskController::class, 'storeComment']);
    Route::post('tasks/{task}/checklist',              [TaskController::class, 'storeChecklist']);
    Route::put('tasks/{task}/checklist/{item}',        [TaskController::class, 'updateChecklist']);
    Route::post('tasks/{task}/lock',                   [TaskController::class, 'lock']);
    Route::post('tasks/{task}/unlock',                 [TaskController::class, 'unlock']);

    // Meetings
    Route::apiResource('meetings', MeetingController::class);
    Route::post('meetings/{meeting}/decisions',                          [MeetingController::class, 'storeDecision']);
    Route::post('meetings/{meeting}/action-items',                       [MeetingController::class, 'storeActionItem']);
    Route::post('meetings/{meeting}/action-items/{actionItem}/convert-to-task', [MeetingController::class, 'convertActionItemToTask']);
    Route::post('meetings/{meeting}/report',                             [MeetingController::class, 'storeReport']);

    // Dynamic Forms (admin management)
    Route::apiResource('forms', DynamicFormController::class);
    Route::get('forms/{form}/questions',          [DynamicFormController::class, 'indexQuestions']);
    Route::post('forms/{form}/questions',         [DynamicFormController::class, 'storeQuestion']);
    Route::put('forms/{form}/questions/{question}', [DynamicFormController::class, 'updateQuestion']);
    Route::delete('forms/{form}/questions/{question}', [DynamicFormController::class, 'destroyQuestion']);
    Route::get('forms/{form}/submissions',        [DynamicFormController::class, 'submissions']);
    Route::put('forms/{form}/submissions/{submission}/status', [DynamicFormController::class, 'updateSubmissionStatus']);

    // Volunteers / HR
    Route::get('volunteers',                        [VolunteerController::class, 'index']);
    Route::post('volunteers',                       [VolunteerController::class, 'store']);
    Route::get('volunteers/{volunteer}',            [VolunteerController::class, 'show']);
    Route::put('volunteers/{volunteer}',            [VolunteerController::class, 'update']);
    Route::put('volunteers/{volunteer}/status',     [VolunteerController::class, 'updateStatus']);
    Route::put('volunteers/{volunteer}/assign-department', [VolunteerController::class, 'assignDepartment']);

    // Partners
    Route::apiResource('partners', PartnerController::class);

    // Partnership requests (admin view + status)
    Route::get('partnership-requests',                           [PartnershipRequestAdminController::class, 'index']);
    Route::get('partnership-requests/{partnershipRequest}',      [PartnershipRequestAdminController::class, 'show']);
    Route::put('partnership-requests/{partnershipRequest}/status', [PartnershipRequestAdminController::class, 'updateStatus']);

    // Volunteer requests (public form submissions — طلب التطوع)
    // Note: These four routes are also duplicated below with hr_manager access.
    Route::get('volunteer-requests',                                           [AdminVolunteerRequestController::class, 'index']);
    Route::get('volunteer-requests/{volunteerRequest}',                        [AdminVolunteerRequestController::class, 'show']);
    Route::patch('volunteer-requests/{volunteerRequest}/status',               [AdminVolunteerRequestController::class, 'updateStatus']);

    // Ambassador applications — admin / super_admin (hr_manager duplicated below)
    Route::get('ambassador-applications',                                          [AdminAmbassadorApplicationController::class, 'index']);
    Route::get('ambassador-applications/analytics',                                [AdminAmbassadorApplicationController::class, 'analytics']);
    Route::get('ambassador-applications/filter-options',                           [AdminAmbassadorApplicationController::class, 'filterOptions']);
    Route::get('ambassador-applications/{ambassadorApplication}',                  [AdminAmbassadorApplicationController::class, 'show']);
    Route::patch('ambassador-applications/{ambassadorApplication}/status',         [AdminAmbassadorApplicationController::class, 'updateStatus']);
    Route::post('ambassador-applications/{ambassadorApplication}/notes',           [AdminAmbassadorApplicationController::class, 'storeNote']);
    Route::delete('ambassador-applications/{ambassadorApplication}/notes/{noteId}',[AdminAmbassadorApplicationController::class, 'deleteNote']);
    Route::delete('ambassador-applications/{ambassadorApplication}',               [AdminAmbassadorApplicationController::class, 'destroy']);

    // NOTE: GET /admin/members (index + show) are registered OUTSIDE this admin group
    // (auth:sanctum only) so that department_manager and other non-admin roles can access
    // the member directory. Do not re-register them here with role:admin.

    // Public team profiles CRUD (team_profiles table — what appears on /ar/team)
    Route::get('team',                                                         [AdminTeamProfileController::class, 'adminTeam']);
    Route::get('team-profiles',                                                [AdminTeamProfileController::class, 'index']);
    Route::post('team-profiles',                                               [AdminTeamProfileController::class, 'store']);
    Route::get('team-profiles/{teamProfile}',                                  [AdminTeamProfileController::class, 'show']);
    Route::put('team-profiles/{teamProfile}',                                  [AdminTeamProfileController::class, 'update']);
    Route::patch('team-profiles/{teamProfile}',                                [AdminTeamProfileController::class, 'update']);
    Route::delete('team-profiles/{teamProfile}',                               [AdminTeamProfileController::class, 'destroy']);
    Route::post('team-profiles/{teamProfile}/photo',                           [AdminTeamProfileController::class, 'uploadPhoto']);
    Route::post('team-profiles/{teamProfile}/create-user-account',             [AdminTeamProfileController::class, 'createUserAccount']);
    Route::post('team-profiles/{teamProfile}/unlink-user',                   [AdminTeamProfileController::class, 'unlinkUser']);

    // Marketing planner
    Route::apiResource('marketing-items', MarketingItemController::class);

    // Support tickets (admin management)
    Route::get('support-tickets',                         [AdminSupportTicketController::class, 'index']);
    Route::get('support-tickets/{supportTicket}',         [AdminSupportTicketController::class, 'show']);
    Route::post('support-tickets/{supportTicket}/reply',  [AdminSupportTicketController::class, 'reply']);
    Route::put('support-tickets/{supportTicket}/status',  [AdminSupportTicketController::class, 'updateStatus']);

    // Activity log (Phase 3 legacy)
    Route::get('activity-logs', [ActivityLogController::class, 'index']);

    // ── Phase 5: Knowledge Base ───────────────────────────────────────────────
    Route::apiResource('knowledge/categories', KnowledgeCategoryController::class)
        ->parameters(['categories' => 'knowledgeCategory']);
    Route::get('knowledge/articles',             [KnowledgeArticleController::class, 'index']);
    Route::post('knowledge/articles',            [KnowledgeArticleController::class, 'store']);
    Route::get('knowledge/articles/{article}',   [KnowledgeArticleController::class, 'show']);
    Route::put('knowledge/articles/{article}',   [KnowledgeArticleController::class, 'update']);
    Route::delete('knowledge/articles/{article}',[KnowledgeArticleController::class, 'destroy']);

    // ── Phase 5: Partner Portal (admin side) ─────────────────────────────────
    Route::get('partners/{partner}/portal',          [AdminPartnerPortalController::class, 'portal']);
    Route::post('partners/{partner}/programs',       [AdminPartnerPortalController::class, 'storeProgram']);
    Route::post('partners/{partner}/reports',        [AdminPartnerPortalController::class, 'storeReport']);

    // ── Phase 5: Advanced LMS — Modules & Lessons & Quizzes ──────────────────
    Route::apiResource('modules', CourseModuleController::class)
        ->parameters(['modules' => 'module']);
    Route::apiResource('lessons', LessonController::class);
    Route::apiResource('quizzes', QuizController::class);
    Route::post('quizzes/{quiz}/questions', [QuizController::class, 'storeQuestion']);

    // ── Phase 5: Automations ─────────────────────────────────────────────────
    Route::apiResource('automation-rules', AutomationRuleController::class)
        ->parameters(['automation-rules' => 'automationRule']);
    Route::get('automation-runs', [AutomationRuleController::class, 'runs']);

    // ── Phase 5: Document Management ─────────────────────────────────────────
    Route::apiResource('document-folders', DocumentFolderController::class)
        ->parameters(['document-folders' => 'documentFolder'])
        ->only(['index', 'store', 'update', 'destroy']);
    Route::get('admin-documents', [DocumentController::class, 'index']);

    // ── Phase 5: Audit Log ────────────────────────────────────────────────────
    Route::get('audit-logs', [AuditLogController::class, 'index']);

    // ── Phase 4: Revenue Engine ───────────────────────────────────────────────

    // Coupons
    Route::apiResource('coupons', CouponController::class);
    Route::post('coupons/validate', [CouponController::class, 'validate']);

    // Scholarships
    Route::get('scholarships',                        [ScholarshipController::class, 'index']);
    Route::post('scholarships',                       [ScholarshipController::class, 'store']);
    Route::get('scholarships/{scholarship}',          [ScholarshipController::class, 'show']);
    Route::put('scholarships/{scholarship}/approve',  [ScholarshipController::class, 'approve']);
    Route::put('scholarships/{scholarship}/reject',   [ScholarshipController::class, 'reject']);

    // Finance dashboard
    Route::get('finance/overview',         [FinanceController::class, 'overview']);
    Route::get('finance/transactions',     [FinanceController::class, 'transactions']);
    Route::get('finance/payments',         [FinanceController::class, 'payments']);
    Route::get('finance/revenue-by-course',[FinanceController::class, 'revenueByCourse']);

    // Chart of Accounts (CoA - شجرة الحسابات) — moved to a dedicated
    // admin/finance-prefixed group further down (with finance_manager
    // access) so this broad admin-only group doesn't need to widen.

    // Program Finance Approval routes are registered below with finance_manager access

    // ── Phase 4: Quality ─────────────────────────────────────────────────────
    Route::get('quality-reviews',                        [QualityReviewController::class, 'index']);
    Route::post('quality-reviews',                       [QualityReviewController::class, 'store']);
    Route::get('quality-reviews/{qualityReview}',        [QualityReviewController::class, 'show']);
    Route::put('quality-reviews/{qualityReview}',        [QualityReviewController::class, 'update']);
    Route::put('quality-reviews/{qualityReview}/approve',[QualityReviewController::class, 'approve']);

    // ── Phase 4: KPI Dashboards ───────────────────────────────────────────────
    Route::prefix('kpi')->group(function () {
        Route::get('overview',      [KpiController::class, 'overview']);
        Route::get('education',     [KpiController::class, 'education']);
        Route::get('finance',       [KpiController::class, 'finance']);
        Route::get('departments',   [KpiController::class, 'departments']);
        Route::get('marketing',     [KpiController::class, 'marketing']);
        Route::get('partnerships',  [KpiController::class, 'partnerships']);
        Route::get('hr',            [KpiController::class, 'hr']);
    });

    // ── Phase 4: Reports ─────────────────────────────────────────────────────
    Route::get('reports',                            [ReportController::class, 'index']);
    Route::post('reports/generate',                  [ReportController::class, 'generate']);
    Route::get('reports/{report}',                   [ReportController::class, 'show']);
    Route::delete('reports/{report}',                [ReportController::class, 'destroy']);
    Route::get('reports/{report}/export/pdf',        [ReportController::class, 'exportPdf']);
    Route::get('reports/{report}/export/excel',      [ReportController::class, 'exportExcel']);

    // ── Phase 7: Webhooks / Integrations / API tokens ────────────────────────
    // Phase 2G.1C — TECHNICAL PLATFORM ADMINISTRATION, narrowed to the admin
    // tier (role:admin; RoleMiddleware passes super_admin/tech_admin through).
    //
    // These three blocks previously inherited this group's operations_manager
    // grant purely by co-location: the group exists for the DEPARTMENT routes
    // near its top, and nothing here is department work. The dedicated aliases
    // the frontend actually calls — /api/integrations, /api/developer/api-tokens
    // and /api/webhooks — are already `role:admin`, so the canonical routes were
    // the only way operations_manager could reach webhooks, integration secrets
    // or API tokens, and no UI ever offered it. This aligns the two spellings.
    //
    // Deliberately NOT root-only: PageAccessCatalog classifies these ADMIN_ONLY
    // ("admin-tier roles (admin/tech_admin/super_admin)"), not SYSTEM_PROTECTED.
    Route::middleware('role:admin')->group(function () {
        Route::get('webhook-endpoints',                  [WebhookEndpointController::class, 'index']);
        Route::post('webhook-endpoints',                 [WebhookEndpointController::class, 'store']);
        Route::get('webhook-endpoints/{webhookEndpoint}',[WebhookEndpointController::class, 'show']);
        Route::put('webhook-endpoints/{webhookEndpoint}',[WebhookEndpointController::class, 'update']);
        Route::delete('webhook-endpoints/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy']);
        Route::post('webhook-endpoints/{webhookEndpoint}/test', [WebhookEndpointController::class, 'test']);
        Route::get('webhook-deliveries',                 [WebhookEndpointController::class, 'deliveries']);

        Route::get('integrations',                       [IntegrationSettingController::class, 'index']);
        Route::put('integrations/{key}',                 [IntegrationSettingController::class, 'upsert']);

        Route::get('api-tokens',                         [ApiTokenController::class, 'index']);
        Route::post('api-tokens',                        [ApiTokenController::class, 'store']);
        Route::delete('api-tokens/{id}',                 [ApiTokenController::class, 'destroy']);
    });

    // ── Email settings & logs ─────────────────────────────────────────────────
    // Read-only for the broader admin group above; modifying settings is
    // restricted below to super_admin/executive_admin only.
    Route::get('email-settings',            [EmailSettingsController::class, 'index']);
    Route::get('email-logs',                [EmailLogsController::class, 'index']);
    Route::get('email-logs/stats',          [EmailLogsController::class, 'stats']);
    Route::get('email-logs/chart',          [EmailLogsController::class, 'chart']);
    Route::get('email-logs/queue',          [EmailLogsController::class, 'queue']);
    Route::get('email-logs/{id}',           [EmailLogsController::class, 'show']);
    Route::post('email-logs/{id}/retry',    [EmailLogsController::class, 'retry']);

    // ── Phase 7: Notification templates & logs ────────────────────────────────
    Route::get('notification-templates',             [NotificationTemplateController::class, 'index']);
    Route::post('notification-templates',            [NotificationTemplateController::class, 'store']);
    Route::put('notification-templates/{notificationTemplate}', [NotificationTemplateController::class, 'update']);
    Route::delete('notification-templates/{notificationTemplate}', [NotificationTemplateController::class, 'destroy']);
    Route::get('notification-logs',                  [NotificationTemplateController::class, 'logs']);

    // ── Phase 7: External event logs ─────────────────────────────────────────
    Route::get('external-event-logs',                [ExternalEventLogController::class, 'index']);

    // ── RBAC: Role management (view: admin+; create/edit/delete: super_admin only) ─
    Route::get('roles',                              [RoleController::class, 'index']);
    Route::get('roles/options',                      [UserRoleController::class, 'roleOptions']);
    Route::get('roles/{role}',                       [RoleController::class, 'show']);
    Route::post('roles',                             [RoleController::class, 'store']);
    Route::put('roles/{role}',                       [RoleController::class, 'update']);
    Route::delete('roles/{role}',                    [RoleController::class, 'destroy']);
    Route::get('roles/{role}/permissions',           [RoleController::class, 'permissions']);
    Route::put('roles/{role}/permissions',           [RoleController::class, 'setPermissions']);
    // Phase 2B — role DEFAULT PAGE ACCESS. Separate from the permissions
    // endpoints above on purpose: PAGE ACCESS != BUSINESS AUTHORIZATION.
    // Authorization mirrors setPermissions() inside the controller (the route
    // role list here is only the usual coarse pre-filter).
    Route::get('roles/{role}/page-access',           [\App\Http\Controllers\Admin\RolePageDefaultController::class, 'show']);
    Route::put('roles/{role}/page-access',           [\App\Http\Controllers\Admin\RolePageDefaultController::class, 'update']);
    Route::post('roles/{role}/grant-permission',     [RoleController::class, 'grantPermission']);
    Route::post('roles/{role}/revoke-permission',    [RoleController::class, 'revokePermission']);

    // ── RBAC: Permission catalog ──────────────────────────────────────────────
    Route::get('permissions',                        [PermissionController::class, 'index']);
    Route::get('me/permissions',                     [PermissionController::class, 'myPermissions']);

    // ── RBAC: User role assignment ────────────────────────────────────────────
    Route::put('users/{user}/role',                  [UserRoleController::class, 'assignRole']);
    Route::get('users/{user}/permissions',           [UserRoleController::class, 'getUserPermissions']);


});

// ── Phase 8: AI Admin (Technical AI Platform — usage/tokens/generations/
// automations/prompts. Deliberately NOT granted to ai_manager: that role is
// the organizational AI Department manager, a normal department leadership
// role, and must not automatically receive technical AI-platform access.
// Preserved for admin/super_admin/tech_admin. See ai-department-and-
// ai-platform-separation — Domain A (organizational) vs Domain B (technical). ──
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin'])->prefix('admin/ai')->group(function () {
    // Prompt management
    Route::get('prompts',                    [AiPromptController::class, 'index']);
    Route::post('prompts',                   [AiPromptController::class, 'store']);
    Route::get('prompts/{aiPrompt}',         [AiPromptController::class, 'show']);
    Route::put('prompts/{aiPrompt}',         [AiPromptController::class, 'update']);
    Route::delete('prompts/{aiPrompt}',      [AiPromptController::class, 'destroy']);

    // AI Insights
    Route::get('insights',                   [AiInsightController::class, 'index']);
    Route::post('insights/generate',         [AiInsightController::class, 'generate']);
    Route::delete('insights/{aiInsight}',    [AiInsightController::class, 'destroy']);

    // AI Automation rules
    Route::get('automations',                [AiAutomationController::class, 'index']);
    Route::post('automations',               [AiAutomationController::class, 'store']);
    Route::get('automations/{aiAutomationRule}',            [AiAutomationController::class, 'show']);
    Route::put('automations/{aiAutomationRule}',            [AiAutomationController::class, 'update']);
    Route::delete('automations/{aiAutomationRule}',         [AiAutomationController::class, 'destroy']);
    Route::get('automations/{aiAutomationRule}/executions', [AiAutomationController::class, 'executions']);
});

// AI Usage logs & cost tracking — deliberately its OWN group gated by the
// existing `view_ai_usage` permission alone (App\Support\Permissions), not
// nested under the role:admin,super_admin,tech_admin gate above: a role
// check there would run before any permission check and defeat the point
// of a permission-based grant. admin/super_admin/tech_admin already hold
// `view_ai_usage` via the role-permission pivot (PermissionMiddleware also
// unconditionally bypasses super_admin/tech_admin), so their access is
// unchanged; any other role — including ai_manager — needs this permission
// explicitly, never the role slug.
Route::middleware(['auth:sanctum', 'permission:view_ai_usage'])->prefix('admin/ai')->group(function () {
    Route::get('usage',          [AiUsageController::class, 'index']);
    Route::get('usage/summary',  [AiUsageController::class, 'summary']);
});

// ─── Phase 7: Mobile API (auth required, any role) ───────────────────────────
Route::middleware(['auth:sanctum'])->prefix('mobile')->group(function () {
    Route::get('home',                               [MobileController::class, 'home']);
    Route::get('dashboard',                          [MobileController::class, 'dashboard']);
    Route::get('profile',                            [MobileController::class, 'profile']);
    Route::put('profile',                            [MobileController::class, 'updateProfile']);
    Route::get('my-learning',                        [MobileController::class, 'myLearning']);
    Route::get('notifications',                      [MobileController::class, 'notifications']);
    Route::put('notifications/{id}/read',            [MobileController::class, 'markNotificationRead']);
    Route::get('upcoming-sessions',                  [MobileController::class, 'upcomingSessions']);
    Route::get('certificates',                       [MobileController::class, 'certificates']);
});

// ─── Phase 7: Calendar (auth required) ───────────────────────────────────────
Route::middleware(['auth:sanctum'])->prefix('calendar')->group(function () {
    Route::get('export.ics',                         [CalendarController::class, 'exportAll']);
    Route::get('events',                             [CalendarController::class, 'index']);
    Route::post('events',                            [CalendarController::class, 'store']);
    Route::put('events/{calendarEvent}',             [CalendarController::class, 'update']);
    Route::delete('events/{calendarEvent}',          [CalendarController::class, 'destroy']);
    Route::get('events/{calendarEvent}/ics',         [CalendarController::class, 'downloadIcs']);
});

// ─── Phase 7: Notification preferences (auth required) ───────────────────────
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('notification-preferences',           [NotificationPreferenceController::class, 'index']);
    Route::put('notification-preferences',           [NotificationPreferenceController::class, 'update']);
});

// ═══════════════════════════════════════════════════════════════════════════════
// ROUTE ALIASES — bridge frontend URL expectations to existing controllers
// ═══════════════════════════════════════════════════════════════════════════════

// ─── Notification aliases ────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    // Frontend uses PATCH/POST; backend originals use PUT/PUT
    Route::patch('notifications/{id}/read',  [NotificationController::class, 'markRead']);
    Route::post('notifications/read-all',    [NotificationController::class, 'markAllRead']);
    Route::delete('notifications/{id}',      [NotificationController::class, 'delete']);
    // Frontend uses /notifications/preferences; backend uses /notification-preferences
    Route::post('notifications/preferences/reset', [NotificationPreferenceController::class, 'reset']);
    Route::get('notifications/preferences',   [NotificationPreferenceController::class, 'index']);
    Route::patch('notifications/preferences', [NotificationPreferenceController::class, 'update']);
});

// ─── Program Finance Approvals — finance_manager + admin + super_admin ──────
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,finance_manager'])
    ->prefix('admin')
    ->group(function () {
        Route::get('finance/program-approvals',                                       [\App\Http\Controllers\Admin\ProgramFinanceApprovalController::class, 'index']);
        Route::post('finance/program-approvals/{programFinanceApproval}/approve',     [\App\Http\Controllers\Admin\ProgramFinanceApprovalController::class, 'approve']);
        Route::post('finance/program-approvals/{programFinanceApproval}/reject',      [\App\Http\Controllers\Admin\ProgramFinanceApprovalController::class, 'reject']);
    });

// ─── Admin dashboard alias ───────────────────────────────────────────────────
Route::get('dashboard/admin', [AdminController::class, 'dashboard'])
    ->middleware(['auth:sanctum', 'role:admin']);

// ─── LMS routes (student/auth — frontend uses /lms/*) ────────────────────────
Route::middleware('auth:sanctum')->prefix('lms')->group(function () {
    Route::get('courses/{courseId}/modules', [StudentLearningController::class, 'modules']);
    Route::get('lessons/{lesson}',           [StudentLearningController::class, 'lesson']);
    Route::post('quizzes/{quiz}/submit',     [\App\Http\Controllers\Student\StudentQuizController::class, 'submit']);
});

// ─── Admin LMS (frontend uses /admin/lms/*) ──────────────────────────────────
Route::middleware(['auth:sanctum', 'role:admin,programs_manager,tech_admin,super_admin'])->prefix('admin/lms')->group(function () {
    // Structure read-only
    Route::get('modules', [CourseModuleController::class, 'index']);
    Route::get('lessons', [LessonController::class,       'index']);
    Route::get('quizzes', [QuizController::class,         'index']);

    // Sessions CRUD + link-open tracking
    Route::get('sessions',                            [AdminLmsSessionController::class, 'index']);
    Route::post('sessions',                           [AdminLmsSessionController::class, 'store']);
    Route::get('sessions/{session}',                  [AdminLmsSessionController::class, 'show']);
    Route::put('sessions/{session}',                  [AdminLmsSessionController::class, 'update']);
    Route::patch('sessions/{session}',                [AdminLmsSessionController::class, 'update']);
    Route::delete('sessions/{session}',               [AdminLmsSessionController::class, 'destroy']);
    Route::get('sessions/{session}/link-opens',       [AdminLmsSessionController::class, 'linkOpens']);

    // Attendance (read-only list; individual session records via instructor routes)
    Route::get('attendance',                 [AdminLmsAttendanceController::class, 'index']);

    // Assignments CRUD
    Route::get('assignments',                        [AdminLmsAssignmentController::class, 'index']);
    Route::post('assignments',                       [AdminLmsAssignmentController::class, 'store']);
    Route::get('assignments/{courseAssignment}',     [AdminLmsAssignmentController::class, 'show']);
    Route::put('assignments/{courseAssignment}',     [AdminLmsAssignmentController::class, 'update']);
    Route::patch('assignments/{courseAssignment}',   [AdminLmsAssignmentController::class, 'update']);
    Route::delete('assignments/{courseAssignment}',  [AdminLmsAssignmentController::class, 'destroy']);

    // Materials CRUD
    Route::get('materials/stats',            [AdminLmsMaterialController::class,   'stats']);
    Route::get('materials',                  [AdminLmsMaterialController::class,   'index']);
    Route::post('materials',                 [AdminLmsMaterialController::class,   'store'])
        ->middleware('throttle:20,60');
    Route::get('materials/{material}',       [AdminLmsMaterialController::class,   'show']);
    Route::put('materials/{material}',       [AdminLmsMaterialController::class,   'update']);
    Route::patch('materials/{material}',     [AdminLmsMaterialController::class,   'update']);
    Route::delete('materials/{material}',    [AdminLmsMaterialController::class,   'destroy']);

    // Evaluations (read-only for now)
    Route::get('evaluations',                [AdminLmsEvaluationController::class, 'index']);

    // Progress (read-only overview + student detail)
    Route::get('progress',                   [AdminLmsProgressController::class, 'index']);
    Route::get('progress/students/{user}',   [AdminLmsProgressController::class, 'student']);
});

// ─── Operations: department routes — accessible by admin, department_manager, programs_manager ─
// (department_manager scopes to own dept; programs_manager needs read-only for course dept dropdown)
Route::middleware(['auth:sanctum', 'role:admin,department_manager,programs_manager,operations_manager'])->prefix('operations')->group(function () {
    Route::get('departments',              [DepartmentController::class, 'index']);
    Route::get('departments/{department}', [DepartmentController::class, 'show']);
    Route::get('departments/{department}/overview', [DepartmentController::class, 'overview']);
});

// ─── Operations: support tickets — extended roles (support_agent, hr_manager can view/reply) ─
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,support_agent,hr_manager'])->prefix('operations')->group(function () {
    Route::get('support-tickets',                                      [AdminSupportTicketController::class,      'index']);
    Route::get('support-tickets/assignees',                            [AdminSupportTicketController::class,      'assignees']);
    Route::get('support-tickets/{supportTicket}',                      [AdminSupportTicketController::class,      'show']);
    Route::get('support-tickets/{supportTicket}/activity',             [AdminSupportTicketController::class,      'activity']);
    Route::post('support-tickets/{supportTicket}/replies',             [AdminSupportTicketController::class,      'reply']);
    Route::patch('support-tickets/{supportTicket}',                    [AdminSupportTicketController::class,      'updateStatus']);
    Route::patch('support-tickets/{supportTicket}/assign',             [AdminSupportTicketController::class,      'assign']);
    Route::patch('support-tickets/{supportTicket}/resolve',            [AdminSupportTicketController::class,      'resolve']);
    Route::delete('support-tickets/{supportTicket}',                   [AdminSupportTicketController::class,      'destroy']);
});

// ─── Operations aliases (frontend uses /operations/*, backend uses /admin/*) ─
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,operations_manager'])->prefix('operations')->group(function () {
    Route::get('dashboard',                                            [AdminController::class,                   'operationsDashboard']);
    // NOTE: GET departments + departments/{id} are registered ABOVE this group with role:admin,department_manager
    // so that Dept. Managers can access their own department data.
    Route::get('tasks',                                                [TaskController::class,                    'index']);
    Route::get('tasks/{task}',                                         [TaskController::class,                    'show']);
    Route::patch('tasks/{task}',                                       [TaskController::class,                    'update']);
    Route::post('tasks',                                               [TaskController::class,                    'store']);
    Route::post('tasks/{task}/comments',                               [TaskController::class,                    'storeComment']);
    Route::get('meetings',                                             [MeetingController::class,                 'index']);
    Route::get('meetings/{meeting}',                                   [MeetingController::class,                 'show']);
    Route::get('forms',                                                [DynamicFormController::class,             'index']);
    Route::get('forms/{form}',                                         [DynamicFormController::class,             'show']);
    Route::get('forms/{form}/submissions',                             [DynamicFormController::class,             'submissions']);
    Route::post('forms',                                               [DynamicFormController::class,             'store']);
    Route::put('forms/{form}',                                         [DynamicFormController::class,             'update']);
    Route::patch('volunteers/{volunteer}',                             [VolunteerController::class,               'update']);
});


// ─── Operations: volunteers read — HR/community dashboards consume this list ─
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,executive_admin,hr_manager,operations_manager,community_manager'])->prefix('operations')->group(function () {
    Route::get('volunteers',             [VolunteerController::class, 'index']);
    Route::get('volunteers/{volunteer}', [VolunteerController::class, 'show']);
});

// ─── Operations: marketing items — the marketing team manages its own board ──
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,executive_admin,marketing_manager,marketing,operations_manager'])->prefix('operations')->group(function () {
    Route::get('marketing',                   [MarketingItemController::class, 'index']);
    Route::patch('marketing/{marketingItem}', [MarketingItemController::class, 'update']);
});

// ─── Department meeting lounge (the frontend's /department/meetings surface) ─
// كانت الواجهة تنادي هذه المسارات وهي غير موجودة إطلاقًا — 404 على كل صالة
// الاجتماعات. تُدار بنفس متحكم الاجتماعات، والتقرير الدوري يُخزَّن في نظام
// «تقارير الاجتماعات» الجديد فيصل الأرشيف نفسه.
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,executive_admin,programs_manager,instructor,hr_manager,finance_manager,marketing,marketing_manager,quality,quality_manager,support_agent,operations_manager,partnerships_manager,community_manager,department_manager'])->prefix('department')->group(function () {
    Route::get('meetings',                                        [MeetingController::class, 'index']);
    Route::post('meetings',                                       [MeetingController::class, 'store']);
    Route::get('meetings/{meeting}',                              [MeetingController::class, 'show']);
    Route::patch('meetings/{meeting}/action-items/{actionItem}',  [MeetingController::class, 'updateActionItem']);
    Route::post('meetings/{meeting}/report',                      [MeetingController::class, 'report']);
});

// ─── Operations: Partnerships (accessible to partnerships_manager as well) ─
// Full CRUD via apiResource (adds destroy over the old hand-rolled subset);
// role list widened to operations_manager/partnerships_manager per EMC-NEW-BACK.
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,operations_manager,partnerships_manager'])->prefix('operations')->group(function () {
    // Named distinctly from the /admin/partners apiResource above (line ~883) —
    // both default to "partners.*" and Laravel silently lets the later
    // registration win at runtime, but route:cache hard-fails on the collision.
    Route::apiResource('partners', PartnerController::class)->names('operations.partners');
    Route::get('partnership-requests',                                 [PartnershipRequestAdminController::class, 'index']);
    Route::get('partnership-requests/{partnershipRequest}',            [PartnershipRequestAdminController::class, 'show']);
    Route::patch('partnership-requests/{partnershipRequest}',          [PartnershipRequestAdminController::class, 'updateStatus']);
});

// ─── Finance aliases (frontend uses /finance/*, backend uses /admin/finance/*) ─
// finance_manager role has full finance permissions (VIEW_PAYMENTS, MANAGE_PAYMENTS, etc.)
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,finance_manager'])->prefix('finance')->group(function () {
    Route::get('dashboard',               [FinanceController::class,    'overview']);
    Route::get('payments',                [FinanceController::class,    'payments']);
    Route::get('transactions',            [FinanceController::class,    'transactions']);
    Route::get('orders',                  [AdminOrderController::class, 'index']);
    Route::get('orders/{order}',          [AdminOrderController::class, 'show']);
    Route::get('invoices',                [AdminOrderController::class, 'invoices']);
    Route::get('invoices/{invoice}/download', [AdminOrderController::class, 'downloadInvoice']);
    Route::get('scholarships',            [ScholarshipController::class,'index']);
    Route::patch('scholarships/{scholarship}/approve', [ScholarshipController::class, 'approve']);
    Route::patch('scholarships/{scholarship}/reject',  [ScholarshipController::class, 'reject']);
    Route::get('coupons',                 [CouponController::class,     'index']);
    Route::post('coupons',                [CouponController::class,     'store']);
    Route::get('coupons/{coupon}',        [CouponController::class,     'show']);
    Route::patch('coupons/{coupon}',      [CouponController::class,     'update']);
    Route::delete('coupons/{coupon}',     [CouponController::class,     'destroy']);
    Route::post('coupons/validate',       [CouponController::class,     'validate']);

    // Finance accounts & manual transactions
    Route::get('accounts',                                           [FinanceAccountController::class, 'index']);
    Route::post('accounts',                                          [FinanceAccountController::class, 'store']);
    Route::patch('accounts/{account}',                               [FinanceAccountController::class, 'update']);
    Route::get('accounts/{account}/transactions',                    [FinanceAccountController::class, 'transactions']);
    Route::post('accounts/{account}/transactions',                   [FinanceAccountController::class, 'addTransaction']);
    Route::get('accounts-summary',                                   [FinanceController::class,        'accountsSummary']);

    // Manual payments
    Route::get('manual-payments/stats',                              [ManualPaymentController::class, 'stats']);
    Route::get('purchasables',                                       [ManualPaymentController::class, 'searchPurchasables']);
    Route::get('students/search',                                    [ManualPaymentController::class, 'searchStudents']);
    Route::get('manual-payments',                                    [ManualPaymentController::class, 'index']);
    Route::post('manual-payments',                                   [ManualPaymentController::class, 'store']);
    Route::get('manual-payments/{manualPayment}',                    [ManualPaymentController::class, 'show']);
    Route::patch('manual-payments/{manualPayment}',                  [ManualPaymentController::class, 'update']);
    Route::post('manual-payments/{manualPayment}/confirm',           [ManualPaymentController::class, 'confirm']);
    Route::post('manual-payments/{manualPayment}/reject',            [ManualPaymentController::class, 'reject']);
    Route::post('manual-payments/{manualPayment}/cancel',            [ManualPaymentController::class, 'cancel']);
    Route::get('manual-payments/{manualPayment}/proof',              [ManualPaymentController::class, 'downloadProof']);

    // Account management extras
    Route::delete('accounts/{account}',                              [FinanceAccountController::class, 'destroy']);
    Route::get('accounts/{account}',                                 [FinanceAccountController::class, 'show']);
    Route::post('accounts/{account}/recalculate',                    [FinanceAccountController::class, 'recalculate']);
});

// ─── Chart of Accounts (CoA) — dedicated group, finance_manager included ─────
// Deliberately NOT part of the broad role:admin,super_admin,tech_admin group
// above (routes/api.php ~709) — that group covers unrelated admin-only
// surfaces (departments, tasks, meetings, ambassador applications, ...) that
// finance_manager has no business touching. Scoped narrowly to just the six
// Chart of Accounts endpoints the frontend (chartOfAccountsApi.ts) actually
// calls. Kept on `/admin/finance/...` (not `/finance/...`) because that
// prefix is already the ChartOfAccountsController's live API contract — the
// sibling `/finance/accounts` group above is a different accounting concept
// (FinanceAccountController — cash/payment-gateway accounts), not this one.
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,finance_manager'])
    ->prefix('admin/finance')
    ->group(function () {
        Route::get('chart-of-accounts',               [\App\Http\Controllers\Admin\ChartOfAccountsController::class, 'tree']);
        Route::get('chart-of-accounts/suggest-code',  [\App\Http\Controllers\Admin\ChartOfAccountsController::class, 'suggestCode']);
        Route::get('accounts',                        [\App\Http\Controllers\Admin\ChartOfAccountsController::class, 'index']);
        Route::post('accounts',                       [\App\Http\Controllers\Admin\ChartOfAccountsController::class, 'store']);
        Route::put('accounts/{id}',                   [\App\Http\Controllers\Admin\ChartOfAccountsController::class, 'update']);
        Route::delete('accounts/{id}',                [\App\Http\Controllers\Admin\ChartOfAccountsController::class, 'destroy']);

        // Manual Expenses (سندات المصروفات اليدوية)
        Route::get('expenses/suggest-voucher-no',     [\App\Http\Controllers\Admin\ExpenseController::class, 'suggestVoucherNo']);
        Route::apiResource('expenses',                 \App\Http\Controllers\Admin\ExpenseController::class);
        // Attachments are private: served only through these authorized, audited endpoints.
        Route::get('expenses/{expense}/attachment',         [\App\Http\Controllers\Admin\ExpenseController::class, 'downloadAttachment'])->name('expenses.attachment.download');
        Route::get('expenses/{expense}/attachment/preview', [\App\Http\Controllers\Admin\ExpenseController::class, 'previewAttachment'])->name('expenses.attachment.preview');

        // Manual Revenues (سندات القبض والإيرادات اليدوية)
        Route::get('revenues/suggest-receipt-no',     [\App\Http\Controllers\Admin\RevenueController::class, 'suggestReceiptNo']);
        Route::apiResource('revenues',                 \App\Http\Controllers\Admin\RevenueController::class);
        Route::get('revenues/{revenue}/attachment',         [\App\Http\Controllers\Admin\RevenueController::class, 'downloadAttachment'])->name('revenues.attachment.download');
        Route::get('revenues/{revenue}/attachment/preview', [\App\Http\Controllers\Admin\RevenueController::class, 'previewAttachment'])->name('revenues.attachment.preview');
    });

// ─── KPI unified endpoint (?tab=overview|education|finance|…) ────────────────
// finance_manager, quality_manager, and programs_manager have VIEW_KPI permission
Route::get('kpi', [KpiController::class, 'unified'])
    ->middleware(['auth:sanctum', 'role:admin,finance_manager,quality_manager,programs_manager']);

// ─── Department Meeting Lounge ────────────────────────────────────────────────
Route::middleware(['auth:sanctum'])->prefix('department')->group(function () {
    Route::get('meetings', [App\Http\Controllers\Admin\MeetingController::class, 'index']);
    Route::post('meetings', [App\Http\Controllers\Admin\MeetingController::class, 'store']);
    Route::get('meetings/{meeting}', [App\Http\Controllers\Admin\MeetingController::class, 'show']);
    Route::put('meetings/{meeting}', [App\Http\Controllers\Admin\MeetingController::class, 'update']);
    Route::post('meetings/{meeting}/report', [App\Http\Controllers\Admin\MeetingController::class, 'storeReport']);
    Route::post('meetings/{meeting}/action-items', [App\Http\Controllers\Admin\MeetingController::class, 'storeActionItem']);
});

// ─── Quality Management System ────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,quality_manager'])->prefix('quality')->group(function () {
    Route::get('dashboard',   [QualityController::class, 'dashboard']);
    Route::get('workshops',   [QualityController::class, 'workshops']);
    Route::get('team',        [QualityController::class, 'team']);
    Route::get('audit-logs',  [QualityController::class, 'auditLogs']);
    Route::get('compliance',  [QualityController::class, 'compliance']);

    // Reviews (reuse existing controller)
    Route::get('reviews',                       [QualityReviewController::class, 'index']);
    Route::post('reviews',                      [QualityReviewController::class, 'store']);
    Route::get('reviews/{qualityReview}',       [QualityReviewController::class, 'show']);
    Route::patch('reviews/{qualityReview}',     [QualityReviewController::class, 'update']);
    Route::post('reviews/{qualityReview}/approve', [QualityReviewController::class, 'approve']);

    // Incidents
    Route::get('incidents',                          [QualityController::class, 'incidents']);
    Route::post('incidents',                         [QualityController::class, 'storeIncident']);
    Route::patch('incidents/{qualityIncident}',      [QualityController::class, 'updateIncident']);

    // Corrective Actions
    Route::get('corrective-actions',                                    [QualityController::class, 'correctiveActions']);
    Route::post('corrective-actions',                                   [QualityController::class, 'storeCorrectiveAction']);
    Route::patch('corrective-actions/{qualityCorrectiveAction}',        [QualityController::class, 'updateCorrectiveAction']);
});

// ─── Reports aliases (frontend uses /reports directly) ───────────────────────
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,programs_manager,operations_manager'])->group(function () {
    Route::get('reports',          [ReportController::class, 'index']);
    Route::post('reports/generate',[ReportController::class, 'generate']);
});

// ─── Audit logs alias (frontend uses /audit-logs directly) ───────────────────
Route::get('audit-logs', [AuditLogController::class, 'index'])
    ->middleware(['auth:sanctum', 'role:admin']);

// ─── Webhook aliases (frontend uses /webhooks/*, backend uses /admin/webhook-endpoints/*) ─
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('webhooks')->group(function () {
    Route::get('/',                              [WebhookEndpointController::class, 'index']);
    Route::post('/',                             [WebhookEndpointController::class, 'store']);
    Route::get('/{webhookEndpoint}',             [WebhookEndpointController::class, 'show']);
    Route::patch('/{webhookEndpoint}',           [WebhookEndpointController::class, 'update']);
    Route::delete('/{webhookEndpoint}',          [WebhookEndpointController::class, 'destroy']);
    Route::get('/{webhookEndpoint}/deliveries',  [WebhookEndpointController::class, 'deliveries']);
    Route::post('/{webhookEndpoint}/test',       [WebhookEndpointController::class, 'test']);
});

// ─── Integration aliases (frontend uses /integrations/*) ─────────────────────
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('integrations')->group(function () {
    Route::get('/', [IntegrationSettingController::class, 'index']);
    Route::get('/whatsapp', function () {
        $s = \App\Models\IntegrationSetting::where('provider', 'whatsapp')->first();
        return response()->json(['success' => true, 'data' => $s?->safeConfig() ?? []]);
    });
    Route::get('/email', function () {
        $s = \App\Models\IntegrationSetting::whereIn('provider', ['email', 'smtp'])->first();
        return response()->json(['success' => true, 'data' => $s?->safeConfig() ?? []]);
    });
    Route::post('/whatsapp/test', function () {
        return response()->json(['success' => true, 'message' => 'Test message queued']);
    });
    Route::post('/email/test', function () {
        return response()->json(['success' => true, 'message' => 'Test email queued']);
    });
    Route::put('/{key}', [IntegrationSettingController::class, 'upsert']);
});

// ─── Developer API token aliases (frontend uses /developer/api-tokens) ───────
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('developer')->group(function () {
    Route::get('api-tokens',          [ApiTokenController::class, 'index']);
    Route::post('api-tokens',         [ApiTokenController::class, 'store']);
    Route::delete('api-tokens/{id}',  [ApiTokenController::class, 'destroy']);
});

// ─── Documents aliases ───────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('documents/folders',  [DocumentFolderController::class, 'index']);
    Route::post('documents/upload',  [DocumentController::class,       'store']);
});

// ─── Support public alias (frontend uses /support/tickets) ───────────────────
Route::post('support/tickets', [PublicSupportTicketController::class, 'store'])
    ->middleware('throttle:workshop');

// ─── Contact form (public) ───────────────────────────────────────────────────
Route::post('contact', function (\App\Http\Requests\ContactFormRequest $request) {
    $typeMap = [
        'general'     => 'student',
        'partnership' => 'partner',
        'volunteer'   => 'volunteer',
        'technical'   => 'technical',
        'tech'        => 'technical',
    ];

    $data = $request->validated();

    try {
        $category = $data['category'] ?? $data['type'] ?? 'general';
        $subject  = $data['subject'];

        // Look up existing user by email (optional link)
        $existingUser = \App\Models\User::where('email', $data['email'])->first();

        $ticket = \App\Models\SupportTicket::create([
            'full_name'    => $data['name'],
            'name'         => $data['name'],
            'email'        => $data['email'],
            'phone'        => $data['phone'] ?? null,
            'request_type' => $category,
            'type'         => $typeMap[$category] ?? 'student',
            'priority'     => 'medium',
            'subject'      => $subject,
            'message'      => $data['message'],
            'status'       => 'new',
            'source'       => 'contact_form',
            'user_id'      => $existingUser?->id ?? $request->user()?->id,
        ]);

        $ticketNumber = $ticket->ticket_number ?? ('TK-' . $ticket->id);
        $submittedAt  = now()->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i');

        // Confirmation to visitor (from: noreply, reply-to: support)
        \App\Services\EmailService::dispatch(
            'contact_confirmation',
            $data['email'],
            $data['name'],
            $ticket->user_id,
            new \App\Mail\ContactConfirmationMail($data['name'], $subject, $data['message'], $submittedAt, $ticketNumber),
            'تم استلام رسالتك — منصة EMC',
        );

        // Notification to support team (to: support@, reply-to: visitor)
        $adminEmail = (string) config('mail.support_email', '');
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            \App\Services\EmailService::dispatch(
                'contact_admin_notification',
                $adminEmail,
                (string) config('mail.support_name', 'EMC Support'),
                null,
                new \App\Mail\ContactAdminNotificationMail(
                    $data['name'],
                    $data['email'],
                    $data['phone'] ?? null,
                    $subject,
                    $data['message'],
                    $category,
                    $submittedAt,
                    null,
                    $ticketNumber,
                ),
                'رسالة جديدة من نموذج التواصل — ' . $data['name'],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال رسالتك بنجاح. تم إنشاء تذكرة دعم وسنقوم بالرد عليك قريباً.',
            'data'    => [
                'ticket_id'     => $ticket->id,
                'ticket_number' => $ticketNumber,
            ],
        ]);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Contact form error', [
            'error' => $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
        ]);
        return response()->json([
            'success' => false,
            'message' => 'تعذر إرسال الرسالة حالياً. يرجى المحاولة لاحقاً.',
        ], 500);
    }
})->middleware('throttle:5,1');

// ─── Platform scale (admin / tech admin infrastructure + counts) ─────────────
Route::get('platform/scale', [PlatformScaleController::class, 'show'])
    ->middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin', 'throttle:api']);

// ─── Mobile readiness (auth required) ────────────────────────────────────────
Route::get('mobile/readiness', [MobileController::class, 'home'])
    ->middleware('auth:sanctum');

// ─── Volunteer requests — accessible to hr_manager + admin roles ─────────────
Route::middleware(['auth:sanctum', 'role:hr_manager,admin,super_admin,tech_admin'])->prefix('admin')->group(function () {
    // Allow hr_manager to view/update volunteer requests
    Route::get('volunteer-requests',                                       [AdminVolunteerRequestController::class, 'index']);
    Route::get('volunteer-requests/{volunteerRequest}',                    [AdminVolunteerRequestController::class, 'show']);
    Route::patch('volunteer-requests/{volunteerRequest}/status',           [AdminVolunteerRequestController::class, 'updateStatus']);
    Route::post('volunteer-requests/{volunteerRequest}/convert-to-member', [AdminVolunteerRequestController::class, 'convertToMember']);

    // Ambassador applications — accessible to hr_manager + admin + super_admin
    Route::get('ambassador-applications',                                          [AdminAmbassadorApplicationController::class, 'index']);
    Route::get('ambassador-applications/analytics',                                [AdminAmbassadorApplicationController::class, 'analytics']);
    Route::get('ambassador-applications/filter-options',                           [AdminAmbassadorApplicationController::class, 'filterOptions']);
    Route::get('ambassador-applications/{ambassadorApplication}',                  [AdminAmbassadorApplicationController::class, 'show']);
    Route::patch('ambassador-applications/{ambassadorApplication}/status',         [AdminAmbassadorApplicationController::class, 'updateStatus']);
    Route::post('ambassador-applications/{ambassadorApplication}/notes',           [AdminAmbassadorApplicationController::class, 'storeNote']);
    Route::delete('ambassador-applications/{ambassadorApplication}/notes/{noteId}',[AdminAmbassadorApplicationController::class, 'deleteNote']);
});

// ─── Volunteer HR profile — self-service (any authenticated user) ───────────
Route::middleware(['auth:sanctum'])->prefix('volunteer')->group(function () {
    Route::get('hr-profile',                    [VolunteerHrProfileController::class, 'show']);
    Route::post('hr-profile',                   [VolunteerHrProfileController::class, 'store']);
    Route::put('hr-profile/{volunteerHrProfile}', [VolunteerHrProfileController::class, 'update']);
});

// Reference data: department -> job-title options, used by both the
// volunteer HR form and the HR review UI. Non-sensitive, any authenticated user.
Route::middleware(['auth:sanctum'])->get('job-titles', [\App\Http\Controllers\JobTitleController::class, 'index']);

// Active department list for the volunteer HR form's own department picker.
// Deliberately NOT the HR-only /hr/volunteer-hr-profiles/filter-options
// endpoint (role:hr_manager,admin,super_admin,tech_admin) — the volunteer
// applicant filling out this form is ordinarily none of those roles, and
// reusing that endpoint here silently 403'd their department dropdown.
Route::middleware(['auth:sanctum'])->get('departments/options', function () {
    return response()->json([
        'success' => true,
        'data' => \App\Models\Department::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar', 'name']),
    ]);
});

// ─── HR Manager portal (auth:sanctum + role:hr_manager,admin,super_admin,tech_admin) ────
Route::middleware(['auth:sanctum', 'role:hr_manager,admin,super_admin,tech_admin'])->prefix('hr')->group(function () {
    // NOTE: "volunteer-hr-profiles" (not "volunteers") — /hr/volunteers is
    // already taken by HrDashboardController::volunteers() (the older,
    // thinner VolunteerProfile-backed listing), which is left untouched.
    Route::get('volunteer-hr-profiles',                                [HrVolunteerProfileController::class, 'index']);
    Route::get('volunteer-hr-profiles/filter-options',                 [HrVolunteerProfileController::class, 'filterOptions']);
    Route::get('volunteer-hr-profiles/{volunteerHrProfile}',            [HrVolunteerProfileController::class, 'show']);
    Route::patch('volunteer-hr-profiles/{volunteerHrProfile}/review',   [HrVolunteerProfileController::class, 'review']);
    Route::post('volunteer-hr-profiles/{volunteerHrProfile}/approve',   [HrVolunteerProfileController::class, 'approve']);
    Route::post('volunteer-hr-profiles/{volunteerHrProfile}/reject',    [HrVolunteerProfileController::class, 'reject']);
    // Blob-fetched via axios (Authorization header), not a signed browser-nav
    // link — matches AdminAmbassadorApplicationFileController's convention.
    Route::get('volunteers/{volunteerHrProfile}/cv/preview',            [HrVolunteerProfileController::class, 'previewCv']);
    // cv/download removed — privacy policy: no CV download, preview only.
    Route::get('dashboard',                                [HrDashboardController::class, 'dashboard']);
    Route::get('team',                                     [HrDashboardController::class, 'team']);
    Route::get('volunteers',                               [HrDashboardController::class, 'volunteers']);
    Route::get('instructors',                              [HrDashboardController::class, 'instructors']);
    Route::get('instructors/{instructor}',                 [HrDashboardController::class, 'instructorShow']);
    Route::get('applications',                             [HrDashboardController::class, 'applications']);
    Route::post('applications',                            [HrDashboardController::class, 'storeApplication']);
    Route::patch('applications/{application}/status',      [HrDashboardController::class, 'updateApplicationStatus']);
    Route::get('departments',                              [HrDashboardController::class, 'departments']);
    Route::get('onboarding',                               [HrDashboardController::class, 'onboarding']);
    Route::post('onboarding',                              [HrDashboardController::class, 'storeOnboarding']);
    Route::patch('onboarding/{hrOnboarding}',              [HrDashboardController::class, 'updateOnboarding']);
    Route::get('interviews',                               [HrDashboardController::class, 'interviews']);
    Route::post('interviews',                              [HrDashboardController::class, 'storeInterview']);
    Route::get('tasks',                                    [HrDashboardController::class, 'tasks']);
    Route::get('documents',                                [HrDashboardController::class, 'documents']);
});

// ─── Programs Manager portal ─────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'role:programs_manager,admin,super_admin,tech_admin'])->prefix('programs-manager')->group(function () {
    Route::get('dashboard', [\App\Http\Controllers\ProgramsManager\ProgramsManagerDashboardController::class, 'dashboard']);
});

// programs_manager registration access is now in the combined group above (line ~439).

// ─── Admin certificates (admin + programs_manager) — static paths before wildcard ─
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,programs_manager'])->prefix('admin')->group(function () {
    Route::get('certificates/stats',                          [CertificateController::class, 'stats']);
    Route::get('certificates/eligibility',                    [CertificateController::class, 'eligibility']);
    Route::get('certificates/export',                         [CertificateController::class, 'export'])->middleware('throttle:10,1');

    // Expensive write operations — rate-limited
    Route::post('certificates/bulk-issue',                    [CertificateController::class, 'bulkIssue'])->middleware('throttle:5,1');

    Route::get('certificates/templates',                      [CertificateTemplateController::class, 'index']);
    Route::post('certificates/templates',                     [CertificateTemplateController::class, 'store']);
    Route::get('certificates/templates/default',              [CertificateTemplateController::class, 'getDefault']);
    Route::get('certificates/templates/{template}',           [CertificateTemplateController::class, 'show']);
    Route::put('certificates/templates/{template}',           [CertificateTemplateController::class, 'update']);
    Route::delete('certificates/templates/{template}',        [CertificateTemplateController::class, 'destroy']);
    Route::put('certificates/templates/{template}/activate',  [CertificateTemplateController::class, 'toggleActive']);
    Route::get('certificates/templates/{template}/preview',   [CertificateTemplateController::class, 'preview'])->middleware('throttle:60,1');
    Route::post('certificates/templates/preview-designer',    [CertificateTemplateController::class, 'previewDesigner'])->middleware('throttle:60,1');
    Route::put('certificates/templates/{template}/designer',     [CertificateTemplateController::class, 'saveDesigner']);
    Route::put('certificates/templates/{template}/set-default',  [CertificateTemplateController::class, 'setDefault']);
    Route::post('certificates/templates/{template}/duplicate',   [CertificateTemplateController::class, 'duplicate']);
    Route::post('certificates/templates/{template}/upload-asset', [CertificateTemplateController::class, 'uploadAsset']);

    Route::get('certificates/batches',                        [CertificateBatchController::class, 'index']);
    Route::get('certificates/batches/{batch}',                [CertificateBatchController::class, 'show']);

    // ── Analytics (must be registered before wildcard {certificate} routes) ──
    Route::prefix('certificates/analytics')->middleware('throttle:30,1')->group(function () {
        Route::get('overview',         [CertificateAnalyticsController::class, 'overview']);
        Route::get('trends',           [CertificateAnalyticsController::class, 'trends']);
        Route::get('status-distribution', [CertificateAnalyticsController::class, 'statusDistribution']);
        Route::get('approval',         [CertificateAnalyticsController::class, 'approval']);
        Route::get('pdf',              [CertificateAnalyticsController::class, 'pdf']);
        Route::get('downloads',        [CertificateAnalyticsController::class, 'downloads']);
        Route::get('templates',        [CertificateAnalyticsController::class, 'templates']);
        Route::get('courses',          [CertificateAnalyticsController::class, 'courses']);
        Route::get('workshops',        [CertificateAnalyticsController::class, 'workshops']);
        Route::get('learning-paths',   [CertificateAnalyticsController::class, 'learningPaths']);
        Route::get('batches',          [CertificateAnalyticsController::class, 'batches']);
        Route::get('failures',         [CertificateAnalyticsController::class, 'failures']);
        Route::get('export/{type}',    [CertificateAnalyticsController::class, 'export'])->middleware('throttle:5,1');
    });

    Route::get('certificates',                      [CertificateController::class, 'index']);
    Route::post('certificates',                     [CertificateController::class, 'store']);
    Route::get('certificates/{certificate}',        [CertificateController::class, 'show']);
    Route::post('certificates/{certificate}/approve', [CertificateController::class, 'approve'])->middleware('throttle:30,1');
    Route::post('certificates/{certificate}/issue',  [CertificateController::class, 'issue'])->middleware('throttle:30,1');
    Route::post('certificates/{certificate}/revoke', [CertificateController::class, 'revoke'])->middleware('throttle:20,1');
    Route::post('certificates/{certificate}/regenerate',      [CertificateController::class, 'regenerate'])->middleware('throttle:10,1');
    Route::get('certificates/{certificate}/download',         [CertificateController::class, 'download'])->middleware('throttle:30,1');
    Route::get('certificates/{certificate}/approvals',        [CertificateController::class, 'approvals']);
    Route::post('certificates/{certificate}/approvals',       [CertificateController::class, 'recordApproval'])->middleware('throttle:30,1');
    Route::get('certificates/{certificate}/logs',             [CertificateController::class, 'logs']);
    Route::post('certificates/{certificate}/generate-designer', [CertificateController::class, 'generateDesigner'])->middleware('throttle:10,1');
    Route::post('certificates/{certificate}/retry-pdf',          [CertificateController::class, 'retryPdf'])->middleware('throttle:10,1');
});

// ─── Material file/download: authenticated streams (replaces raw /storage/ links) ─
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,programs_manager'])->group(function () {
    Route::get('admin/lms/materials/{material}/file', [AdminLmsMaterialController::class, 'file'])
        ->name('admin.lms.materials.file');
    Route::get('admin/lms/materials/{material}/download', [AdminLmsMaterialController::class, 'download'])
        ->name('admin.lms.materials.download');
});

// ─── Tech Admin portal ───────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'role:tech_admin,super_admin'])->prefix('tech-admin')->group(function () {
    Route::get('dashboard', [\App\Http\Controllers\TechAdmin\TechAdminDashboardController::class, 'dashboard']);
});

// ═══════════════════════════════════════════════════════════════════════════════
// SUPER_ADMIN / ADMIN — missing CRUD endpoints
// super_admin bypasses all role checks via RoleMiddleware::isSuperAdmin()
// ═══════════════════════════════════════════════════════════════════════════════

// ─── User management (admin + super_admin + tech_admin can read/create/update) ─
// programs_manager also gets user SEARCH so they can find existing students when enrolling in courses
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,programs_manager'])->prefix('admin')->group(function () {
    Route::get('users/search',    [AdminUserController::class, 'search']);
});
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin'])->prefix('admin')->group(function () {
    Route::get('stats',           [AdminUserController::class, 'stats']);
    Route::get('users',           [AdminUserController::class, 'index']);
    Route::post('users',          [AdminUserController::class, 'store']);
    Route::get('users/{user}',    [AdminUserController::class, 'show']);
    Route::put('users/{user}',    [AdminUserController::class, 'update']);
    Route::patch('users/{user}',  [AdminUserController::class, 'update']);

    // ── Student courses + bulk repair ─────────────────────────────────────
    Route::get('students/{user}/courses',      [\App\Http\Controllers\AdminController::class, 'studentCourses']);
    Route::post('registrations/repair-links',  [\App\Http\Controllers\AdminController::class, 'repairRegistrationLinks']);
});

// ─── Delete / restore user — super_admin + tech_admin ───────────────────────
Route::delete('admin/users/{user}', [AdminUserController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'role:super_admin,tech_admin']);
Route::post('admin/users/{id}/restore', [AdminUserController::class, 'restore'])
    ->middleware(['auth:sanctum', 'role:super_admin,tech_admin']);

// ─── HR Manager read-only access to instructors directory ────────────────────
Route::middleware(['auth:sanctum', 'role:hr_manager'])->group(function () {
    Route::get('admin/instructors',        [AdminInstructorController::class, 'index']);
    Route::get('admin/instructors/{user}', [AdminInstructorController::class, 'show']);
});

// ─── Admin Courses CRUD — full management with filters + instructor assignment ─
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,department_manager,programs_manager'])->group(function () {
    Route::get('admin/courses',                                 [AdminCourseController::class, 'index']);
    Route::post('admin/courses',                                [AdminCourseController::class, 'store']);
    Route::get('admin/courses/{course}',                        [AdminCourseController::class, 'show']);
    Route::put('admin/courses/{course}',                        [AdminCourseController::class, 'update']);
    Route::patch('admin/courses/{course}',                      [AdminCourseController::class, 'update']);
    Route::delete('admin/courses/{course}',                     [AdminCourseController::class, 'destroy']);
    Route::post('admin/courses/{course}/assign-instructor',     [AdminCourseController::class, 'assignInstructor']);
    Route::patch('admin/courses/{course}/assign-instructor',    [AdminCourseController::class, 'assignInstructor']);
    Route::put('admin/courses/{course}/assign-instructor',      [AdminCourseController::class, 'assignInstructor']);

    // ── Finance approval: submit for review ──────────────────────────────────
    Route::post('admin/courses/{course}/submit-for-finance',       [\App\Http\Controllers\Admin\ProgramFinanceApprovalController::class, 'submitCourse']);
    Route::post('admin/learning-paths/{learningPath}/submit-for-finance', [\App\Http\Controllers\Admin\ProgramFinanceApprovalController::class, 'submitLearningPath']);

    // ── Course students (manual enrollment + participant list) ────────────────
    Route::get('admin/courses/{course}/students',              [\App\Http\Controllers\Admin\AdminCourseStudentsController::class, 'index']);
    Route::post('admin/courses/{course}/students',             [\App\Http\Controllers\Admin\AdminCourseStudentsController::class, 'store']);
    Route::delete('admin/courses/{course}/students/{user}',    [\App\Http\Controllers\Admin\AdminCourseStudentsController::class, 'destroy']);

    // ── Course content: nested CRUD (all under /admin/courses/{course}/…) ───────
    Route::get('admin/courses/{course}/content',                             [\App\Http\Controllers\Admin\CourseContentController::class, 'content']);
    Route::post('admin/courses/{course}/modules',                            [\App\Http\Controllers\Admin\CourseContentController::class, 'storeModule']);
    Route::post('admin/courses/{course}/modules/reorder',                    [\App\Http\Controllers\Admin\CourseContentController::class, 'reorderModules']);
    Route::post('admin/modules/{module}/lessons/reorder',                    [\App\Http\Controllers\Admin\CourseContentController::class, 'reorderLessons']);
    Route::put('admin/courses/{course}/modules/{module}',                    [\App\Http\Controllers\Admin\CourseContentController::class, 'updateModule']);
    Route::patch('admin/courses/{course}/modules/{module}',                  [\App\Http\Controllers\Admin\CourseContentController::class, 'updateModule']);
    Route::delete('admin/courses/{course}/modules/{module}',                 [\App\Http\Controllers\Admin\CourseContentController::class, 'destroyModule']);
    Route::post('admin/courses/{course}/sessions',                           [\App\Http\Controllers\Admin\CourseContentController::class, 'storeCourseSession']);
    Route::put('admin/courses/{course}/sessions/{courseSession}',            [\App\Http\Controllers\Admin\CourseContentController::class, 'updateCourseSession']);
    Route::patch('admin/courses/{course}/sessions/{courseSession}',          [\App\Http\Controllers\Admin\CourseContentController::class, 'updateCourseSession']);
    Route::delete('admin/courses/{course}/sessions/{courseSession}',         [\App\Http\Controllers\Admin\CourseContentController::class, 'destroyCourseSession']);
    Route::post('admin/courses/{course}/materials',                          [\App\Http\Controllers\Admin\CourseContentController::class, 'storeMaterial']);
    Route::put('admin/courses/{course}/materials/{material}',                [\App\Http\Controllers\Admin\CourseContentController::class, 'updateMaterial']);
    Route::patch('admin/courses/{course}/materials/{material}',              [\App\Http\Controllers\Admin\CourseContentController::class, 'updateMaterial']);
    Route::delete('admin/courses/{course}/materials/{material}',             [\App\Http\Controllers\Admin\CourseContentController::class, 'destroyMaterial']);
    Route::post('admin/courses/{course}/assignments',                        [\App\Http\Controllers\Admin\CourseContentController::class, 'storeAssignment']);
    Route::put('admin/courses/{course}/assignments/{courseAssignment}',      [\App\Http\Controllers\Admin\CourseContentController::class, 'updateAssignment']);
    Route::patch('admin/courses/{course}/assignments/{courseAssignment}',    [\App\Http\Controllers\Admin\CourseContentController::class, 'updateAssignment']);
    Route::delete('admin/courses/{course}/assignments/{courseAssignment}',   [\App\Http\Controllers\Admin\CourseContentController::class, 'destroyAssignment']);
    Route::post('admin/courses/{course}/lessons',                            [\App\Http\Controllers\Admin\CourseContentController::class, 'storeLesson']);
    Route::put('admin/courses/{course}/lessons/{lesson}',                    [\App\Http\Controllers\Admin\CourseContentController::class, 'updateLesson']);
    Route::patch('admin/courses/{course}/lessons/{lesson}',                  [\App\Http\Controllers\Admin\CourseContentController::class, 'updateLesson']);
    Route::delete('admin/courses/{course}/lessons/{lesson}',                 [\App\Http\Controllers\Admin\CourseContentController::class, 'destroyLesson']);

    // Instructor management
    Route::get('admin/instructors',        [AdminInstructorController::class, 'index']);
    Route::post('admin/instructors',       [AdminInstructorController::class, 'store']);
    Route::get('admin/instructors/{user}', [AdminInstructorController::class, 'show']);

    // Programs = tracks alias
    Route::get('admin/programs',            [AdminTrackController::class, 'index']);
    Route::post('admin/programs',           [AdminTrackController::class, 'store']);
    Route::get('admin/programs/{track}',    [AdminTrackController::class, 'show']);
    Route::put('admin/programs/{track}',    [AdminTrackController::class, 'update']);
    Route::patch('admin/programs/{track}',  [AdminTrackController::class, 'update']);
    Route::delete('admin/programs/{track}', [AdminTrackController::class, 'destroy']);
});

// ─── Workshops CRUD (admin + super_admin + tech_admin) ───────────────────────
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin'])->prefix('admin')->group(function () {
    Route::get('workshops',                                 [AdminWorkshopController::class, 'index']);
    Route::post('workshops',                                [AdminWorkshopController::class, 'store']);
    Route::get('workshops/{workshop}',                      [AdminWorkshopController::class, 'show']);
    Route::put('workshops/{workshop}',                      [AdminWorkshopController::class, 'update']);
    Route::patch('workshops/{workshop}',                    [AdminWorkshopController::class, 'update']);
    Route::delete('workshops/{workshop}',                   [AdminWorkshopController::class, 'destroy']);
    Route::post('workshops/{workshop}/assign-instructor',   [AdminWorkshopController::class, 'assignInstructor']);
});

// ─── Learning Paths (public) ─────────────────────────────────────────────────
// optional.sanctum: publicShow includes enrollment_status when authenticated
Route::middleware(['throttle:api', 'optional.sanctum'])->group(function () {
    Route::get('/learning-paths',        [\App\Http\Controllers\Admin\AdminLearningPathController::class, 'publicIndex']);
    Route::get('/learning-paths/{slug}', [\App\Http\Controllers\Admin\AdminLearningPathController::class, 'publicShow']);
});

// ─── Learning Path enrollment & status (auth:sanctum + role:student) ─────────
Route::middleware(['auth:sanctum', 'role:student'])->group(function () {
    Route::post('/learning-paths/{slug}/enroll',             [\App\Http\Controllers\Student\StudentLearningPathController::class, 'enroll']);
    Route::post('/learning-paths/{slug}/checkout',           [\App\Http\Controllers\LearningPathCheckoutController::class, 'checkout'])
        ->middleware('throttle:payments');
    Route::get('/learning-paths/{slug}/enrollment-status',   [\App\Http\Controllers\Student\StudentLearningPathController::class, 'enrollmentStatus']);
});

// ─── Learning Paths CRUD (admin + super_admin + programs_manager) ────────────
Route::middleware(['auth:sanctum', 'role:admin,super_admin,programs_manager'])->prefix('admin')->group(function () {
    Route::get('learning-paths',                               [\App\Http\Controllers\Admin\AdminLearningPathController::class, 'index']);
    Route::post('learning-paths',                              [\App\Http\Controllers\Admin\AdminLearningPathController::class, 'store']);
    Route::get('learning-paths/{learningPath}',                [\App\Http\Controllers\Admin\AdminLearningPathController::class, 'show']);
    Route::put('learning-paths/{learningPath}',                [\App\Http\Controllers\Admin\AdminLearningPathController::class, 'update']);
    Route::patch('learning-paths/{learningPath}',              [\App\Http\Controllers\Admin\AdminLearningPathController::class, 'update']);
    Route::delete('learning-paths/{learningPath}',             [\App\Http\Controllers\Admin\AdminLearningPathController::class, 'destroy']);
    Route::post('learning-paths/{learningPath}/courses',       [\App\Http\Controllers\Admin\AdminLearningPathController::class, 'attachCourses']);
    Route::get('learning-paths/{learningPath}/students',       [\App\Http\Controllers\Admin\AdminLearningPathController::class, 'students']);
    Route::patch('learning-paths/{learningPath}/status',       [\App\Http\Controllers\Admin\AdminLearningPathController::class, 'updateStatus']);
});

// ─── Team members CRUD (admin + super_admin + tech_admin) ────────────────────
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin'])->prefix('admin')->group(function () {
    Route::get('team-members',                  [AdminTeamMemberController::class, 'index']);
    Route::post('team-members',                 [AdminTeamMemberController::class, 'store']);
    Route::get('team-members/{teamMember}',     [AdminTeamMemberController::class, 'show']);
    Route::put('team-members/{teamMember}',     [AdminTeamMemberController::class, 'update']);
    Route::patch('team-members/{teamMember}',   [AdminTeamMemberController::class, 'update']);
    Route::delete('team-members/{teamMember}',  [AdminTeamMemberController::class, 'destroy']);
});

// ─── Departmental (technical) units — no hard delete, only deactivate; write
// access is further gated per-department inside the controller via
// DepartmentAccessService::canManageDepartment (department leader or global) ──
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,department_manager,section_lead,hr_manager,finance_manager,marketing_manager,quality_manager,programs_manager,operations_manager,partnerships_manager,community_manager,ai_manager'])->prefix('admin')->group(function () {
    Route::get('departmental-units',                    [\App\Http\Controllers\Admin\AdminDepartmentalUnitController::class, 'index']);
    Route::post('departmental-units',                   [\App\Http\Controllers\Admin\AdminDepartmentalUnitController::class, 'store']);
    Route::put('departmental-units/{departmentalUnit}',   [\App\Http\Controllers\Admin\AdminDepartmentalUnitController::class, 'update']);
    Route::patch('departmental-units/{departmentalUnit}', [\App\Http\Controllers\Admin\AdminDepartmentalUnitController::class, 'update']);
});

// ─── Workshop Requests: list — any authenticated user (controller filters by role/dept) ──────
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    Route::get('workshop-requests', [WorkshopRequestController::class, 'index']);
});

// ─── Workshop Requests: mutations (admin + super_admin + tech_admin) ─────────
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin'])->prefix('admin')->group(function () {
    Route::put('workshop-requests/{workshopRequest}',      [WorkshopRequestController::class, 'update']);
    Route::patch('workshop-requests/{workshopRequest}',    [WorkshopRequestController::class, 'update']);
    Route::delete('workshop-requests/{workshopRequest}',   [WorkshopRequestController::class, 'destroy']);
});

// ─── Workshop Requests: workflow routes (any authenticated user — controller enforces dept/role) ──
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    Route::get('workshop-requests/{workshopRequest}',                      [WorkshopRequestController::class, 'show']);
    Route::post('workshop-requests/{workshopRequest}/approve',             [WorkshopRequestController::class, 'approve']);
    Route::post('workshop-requests/{workshopRequest}/reject',              [WorkshopRequestController::class, 'reject']);
    Route::get('workshop-requests/{workshopRequest}/workflow-history',     [WorkshopRequestController::class, 'workflowHistory']);
});

// ─── Workshop Requests: department-scoped list (any authenticated user) ───────
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('admin/workshop-requests-queue',            [WorkshopRequestController::class, 'index']);
});

// ─── Financial Requests ───────────────────────────────────────────────────────
// Auth-only: controller enforces fine-grained role checks internally
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('financial-requests/my-context',                                            [\App\Http\Controllers\FinancialRequestController::class, 'myContext']);
    Route::get('financial-requests',                                                       [\App\Http\Controllers\FinancialRequestController::class, 'index']);
    Route::post('financial-requests',                                                      [\App\Http\Controllers\FinancialRequestController::class, 'store']);
    Route::get('financial-requests/{financialRequest}',                                    [\App\Http\Controllers\FinancialRequestController::class, 'show']);
    Route::put('financial-requests/{financialRequest}',                                    [\App\Http\Controllers\FinancialRequestController::class, 'update']);
    Route::post('financial-requests/{financialRequest}/submit',                            [\App\Http\Controllers\FinancialRequestController::class, 'submit']);
    Route::post('financial-requests/{financialRequest}/finance-approve',                   [\App\Http\Controllers\FinancialRequestController::class, 'financeApprove']);
    Route::post('financial-requests/{financialRequest}/finance-reject',                    [\App\Http\Controllers\FinancialRequestController::class, 'financeReject']);
    Route::post('financial-requests/{financialRequest}/return',                            [\App\Http\Controllers\FinancialRequestController::class, 'returnRequest']);
    Route::post('financial-requests/{financialRequest}/executive-approve',                 [\App\Http\Controllers\FinancialRequestController::class, 'executiveApprove']);
    Route::post('financial-requests/{financialRequest}/executive-reject',                  [\App\Http\Controllers\FinancialRequestController::class, 'executiveReject']);
    Route::post('financial-requests/{financialRequest}/attachments',                       [\App\Http\Controllers\FinancialRequestController::class, 'storeAttachment']);
    Route::get('financial-requests/{financialRequest}/attachments/{attachment}/download',  [\App\Http\Controllers\FinancialRequestController::class, 'downloadAttachment']);
});

// ─── Audit Logs (super_admin + admin + tech_admin) ───────────────────────────
Route::middleware(['auth:sanctum', 'role:super_admin,admin,tech_admin'])->prefix('admin')->group(function () {
    Route::get('audit-logs/stats',           [AdminAuditLogController::class, 'stats']);
    Route::get('audit-logs/export',          [AdminAuditLogController::class, 'export']);
    Route::get('audit-logs',                 [AdminAuditLogController::class, 'index']);
    Route::get('audit-logs/{auditLog}',      [AdminAuditLogController::class, 'show']);
});

// ─── Impersonation ────────────────────────────────────────────────────────────
// Stop: authenticated with the impersonation token (role is the target user's role)
// Registered before the dynamic {user} route so "stop" is never model-bound as a user id.
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/admin/impersonate/stop', [ImpersonationController::class, 'stop']);
});
// Start: role:super_admin,admin,tech_admin — permission check (users.impersonate) is enforced
// in the controller, so having the role alone is not sufficient.
Route::middleware(['auth:sanctum', 'role:super_admin,admin,tech_admin'])->group(function () {
    Route::post('/admin/impersonate/{user}', [ImpersonationController::class, 'start'])
        ->whereNumber('user');
});

// ─── /me shortcut (impersonation-aware) ───────────────────────────────────────
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
});

// ─── Self-service profile (any authenticated role) ────────────────────────────
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/profile',        [ProfileController::class, 'show']);
    Route::match(['put', 'patch'], '/profile', [ProfileController::class, 'update']);
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::delete('/profile',     [ProfileController::class, 'destroy']);
});

// ─── Placement tests — admin management ──────────────────────────────────────
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin'])->prefix('admin')->group(function () {
    Route::get( 'placement-tests',                                        [AdminPlacementController::class, 'indexTests']);
    Route::get( 'placement-tests/violations/summary',                     [AdminPlacementController::class, 'violationsSummary']);
    Route::get( 'placement-tests/attempts/{attempt}/violations',          [AdminPlacementController::class, 'attemptViolations']);
    Route::post('courses/{course}/oral-assessment-slots',                 [AdminPlacementController::class, 'storeSlot']);
    Route::get( 'courses/{course}/oral-assessment-slots',                 [AdminPlacementController::class, 'indexSlots']);
    Route::delete('oral-assessment-slots/{slot}',                         [AdminPlacementController::class, 'destroySlot']);
    Route::get( 'oral-assessments',                                       [AdminPlacementController::class, 'indexBookings']);
});

// ─── Compressed uploads (images + PDFs, all authenticated roles) ─────────────
Route::middleware('auth:sanctum')->group(function () {
    // Status and download must be declared before the wildcard {context} POST route.
    Route::get( 'uploads/status/{record}',   [UploadController::class, 'status'])->name('api.uploads.status');
    Route::get( 'uploads/download/{record}', [UploadController::class, 'download'])->name('api.uploads.download');
    Route::post('uploads/{context}',         [UploadController::class, 'store'])->name('api.uploads.store');
});


// ─── Product Updates ─────────────────────────────────────────────────────────
// Named static segments must be declared before {productUpdate} wildcard.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('product-updates/unread-count',          [ProductUpdateController::class, 'unreadCount']);
    Route::get('product-updates/whats-new',             [ProductUpdateController::class, 'whatsNew']);
    Route::get('product-updates',                       [ProductUpdateController::class, 'index']);
    Route::get('product-updates/{productUpdate}',       [ProductUpdateController::class, 'show']);
    Route::post('product-updates/{productUpdate}/read', [ProductUpdateController::class, 'markRead']);
});
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,marketing_manager'])->group(function () {
    Route::get('product-updates/admin-stats',                   [ProductUpdateController::class, 'stats']);
    Route::post('product-updates',                              [ProductUpdateController::class, 'store']);
    Route::patch('product-updates/{productUpdate}',             [ProductUpdateController::class, 'update']);
    Route::delete('product-updates/{productUpdate}',            [ProductUpdateController::class, 'destroy']);
    Route::post('product-updates/{productUpdate}/publish',      [ProductUpdateController::class, 'publish']);
});

// ─── Email Automation Center — write access restricted to Super Admin / ─────
// Executive Admin only; broader admin roles keep GET (read-only) access via
// the 'admin,super_admin,tech_admin' group above.
Route::middleware(['auth:sanctum', 'role:super_admin,executive_admin'])->prefix('admin')->group(function () {
    Route::put('email-settings',            [EmailSettingsController::class, 'update']);
    Route::post('email-settings/test',      [EmailSettingsController::class, 'test']);
});

// ─── English Institute Module (المعهد الإنجليزي) ─────────────────────────────
Route::prefix('institute')->group(function () {

    // Student Routes
    Route::middleware(['auth:sanctum', 'role:student'])->prefix('student')->group(function () {
        Route::post('preferences', [\App\Http\Controllers\Institute\InstituteStudentController::class, 'savePreferences']);
        
        // Placement Test
        Route::get('placement-test', [\App\Http\Controllers\Institute\InstituteStudentController::class, 'getTest']);
        Route::post('placement-test/start', [\App\Http\Controllers\Institute\InstituteStudentController::class, 'startTest']);
        Route::post('placement-test/submit', [\App\Http\Controllers\Institute\InstituteStudentController::class, 'submitTest']);
        
        // Checkout & Payment
        Route::post('coupons/validate', [\App\Http\Controllers\Institute\InstituteStudentController::class, 'validateCoupon']);
        Route::post('checkout', [\App\Http\Controllers\Institute\InstituteStudentController::class, 'checkout']);
        
        // Dashboard
        Route::get('dashboard', [\App\Http\Controllers\Institute\InstituteStudentController::class, 'dashboard']);
    });

    // Admin Routes
    Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->prefix('admin')->group(function () {
        // Levels & Bundles
        Route::apiResource('levels', \App\Http\Controllers\Institute\InstituteAdminController::class);
        
        // Coupons — explicit ->names() avoids colliding with the unrelated
        // top-level Route::apiResource('coupons', CouponController::class)
        // (Phase 4 revenue engine), which also auto-names its routes
        // coupons.*. Same URI (api/institute/admin/coupons), same controller,
        // same authorization — only the route *name* changes.
        Route::apiResource('coupons', \App\Http\Controllers\Institute\InstituteAdminController::class)
            ->names('institute.admin.coupons');
        
        // Class Distribution
        Route::get('waitlist', [\App\Http\Controllers\Institute\InstituteAdminController::class, 'waitlist']);
        Route::post('classes/auto-assign', [\App\Http\Controllers\Institute\InstituteAdminController::class, 'autoAssign']);
        Route::post('classes/manual-transfer', [\App\Http\Controllers\Institute\InstituteAdminController::class, 'manualTransfer']);

        // Classes Management
        Route::get('classes', [\App\Http\Controllers\Institute\InstituteAdminController::class, 'getClasses']);
        Route::post('classes', [\App\Http\Controllers\Institute\InstituteAdminController::class, 'storeClass']);
        Route::get('classes/{id}', [\App\Http\Controllers\Institute\InstituteAdminController::class, 'getClass']);
        Route::put('classes/{id}', [\App\Http\Controllers\Institute\InstituteAdminController::class, 'updateClass']);
        Route::delete('classes/{id}', [\App\Http\Controllers\Institute\InstituteAdminController::class, 'deleteClass']);
        Route::get('instructors', [\App\Http\Controllers\Institute\InstituteAdminController::class, 'getInstructors']);
    });

    // Teacher Routes
    Route::middleware(['auth:sanctum', 'role:instructor'])->prefix('teacher')->group(function () {
        Route::get('classes', [\App\Http\Controllers\Institute\InstituteTeacherController::class, 'index']);
        Route::get('classes/{classGroup}', [\App\Http\Controllers\Institute\InstituteTeacherController::class, 'show']);
        Route::post('classes/{classGroup}/attendance', [\App\Http\Controllers\Institute\InstituteTeacherController::class, 'attendance']);
    });
});

// ─── Department Leadership & Structure Routes (Permissions & Org Chart) ───────
// ENTRY RULE: an administrative/manager role OR the canonical leader of an
// ACTIVE department (departments.leader_id, via DepartmentAccessService).
//
// The previous role-only list encoded an assumption the data does not
// guarantee — that "structurally-non-leader" accounts never hold leader_id.
// departments.leader_id is a plain FK to users, and real rows assign it to
// non-manager roles, so a genuine department leader was refused at the route
// layer while every controller behind it would have authorised them. The
// role.or.leader middleware closes that contradiction at the narrowest point.
//
// This is ENTRY ONLY, and defence-in-depth rather than the authority: every
// method on both controllers independently re-checks via DepartmentAccessService
// (canManageDepartment/getLedDepartmentIds), so a leader of department A still
// cannot touch department B, and permission grants remain gated by
// PermissionDelegationService. No global admin authority is conferred, and
// leadership of an archived/inactive department confers no entry at all.
Route::middleware(['auth:sanctum', 'role.or.leader:admin,super_admin,tech_admin,executive_admin,department_manager,section_lead,finance_manager,marketing_manager,quality_manager,hr_manager,programs_manager,operations_manager,partnerships_manager,community_manager,ai_manager,strategy_planning_manager,digital_ambassadors_manager,advisors_manager'])->prefix('department')->group(function () {
    Route::get('/employees', [\App\Http\Controllers\DepartmentPermissionController::class, 'getEmployees']);
    Route::get('/employees/{employee}/permission-tree', [\App\Http\Controllers\DepartmentPermissionController::class, 'getEmployeePermissionTree']);
    Route::post('/employees/{employee}/permissions/grant', [\App\Http\Controllers\DepartmentPermissionController::class, 'grantPermission']);
    Route::post('/employees/{employee}/permissions/revoke', [\App\Http\Controllers\DepartmentPermissionController::class, 'revokePermission']);

    // Department Structure & Org Chart
    Route::get('/structure', [\App\Http\Controllers\DepartmentStructureController::class, 'getStructure']);
    Route::post('/sections', [\App\Http\Controllers\DepartmentStructureController::class, 'storeSection']);
    Route::put('/sections/{section}', [\App\Http\Controllers\DepartmentStructureController::class, 'updateSection']);
    Route::post('/sections/reorder', [\App\Http\Controllers\DepartmentStructureController::class, 'reorderSections']);
    Route::delete('/sections/{section}', [\App\Http\Controllers\DepartmentStructureController::class, 'destroySection']);
    Route::post('/members/assign-section', [\App\Http\Controllers\DepartmentStructureController::class, 'assignMemberSection']);
});

// ─── Super Admin Routes (Delegatable Permissions Management) ──────────────────
Route::middleware(['auth:sanctum', 'role:super_admin,tech_admin'])->prefix('admin/permissions')->group(function () {
    Route::get('/roles', [\App\Http\Controllers\AdminPermissionController::class, 'getRoles']);
    Route::get('/roles/{role}/delegatable', [\App\Http\Controllers\AdminPermissionController::class, 'getDelegatableConfig']);
    Route::post('/roles/{role}/delegatable/toggle', [\App\Http\Controllers\AdminPermissionController::class, 'toggleDelegatable']);
});

// ─── Page Access Catalog (Phase 1 foundation — read-only) ─────────────────────
// PAGE ACCESS != BUSINESS AUTHORIZATION: this only exposes catalog metadata,
// never grants or checks an actual permission. See PageAccessCatalog docblock.
Route::middleware(['auth:sanctum', 'role:super_admin,tech_admin'])->prefix('admin/access-control')->group(function () {
    Route::get('/pages', [\App\Http\Controllers\Admin\PageAccessCatalogController::class, 'index']);
});

// ─── Phase 2C: User page access overrides (configure) ─────────────────────────
// PAGE ACCESS != BUSINESS AUTHORIZATION. The role list below is only a COARSE
// pre-filter (the same roster used by the /api/department/* routes: admin tier
// plus every role that can legitimately hold departments.leader_id). The real
// authority is enforced inside UserPageAccessOverrideService:
//   - super_admin target  -> super_admin only (ProtectedAuthority)
//   - super_admin/tech_admin -> any eligible (non-SYSTEM_PROTECTED) page
//   - canonical department leader -> delegatable pages only, only for users in
//     departments they actually lead (departments.leader_id), never themselves
// SYSTEM_PROTECTED pages can never be stored here in either state.
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin,executive_admin,department_manager,section_lead,finance_manager,marketing_manager,quality_manager,hr_manager,programs_manager,operations_manager,partnerships_manager,community_manager,ai_manager,strategy_planning_manager,digital_ambassadors_manager,advisors_manager'])
    ->prefix('admin/users')->group(function () {
        Route::get('/{user}/page-access-overrides', [\App\Http\Controllers\Admin\UserPageAccessOverrideController::class, 'show']);
        Route::put('/{user}/page-access-overrides', [\App\Http\Controllers\Admin\UserPageAccessOverrideController::class, 'update']);
        // Phase 2D — read-only inspection of the RESOLVED result (role +
        // department + override + system protection) with provenance. Shares
        // this group's coarse pre-filter; real authority is
        // UserPageAccessOverrideService::canConfigure(), reused so inspection
        // can never be wider than configuration.
        Route::get('/{user}/effective-page-access', [\App\Http\Controllers\Admin\UserEffectivePageAccessController::class, 'show']);
    });

// ─── Phase 2A: Department default page access (configure) ─────────────────────
// PAGE ACCESS != BUSINESS AUTHORIZATION. Configuration authority is
// deliberately limited to super_admin/tech_admin — a department leader must NOT
// be able to widen their own department's default exposure (open product
// decision, see DepartmentPageDefaultController docblock). Eligibility of each
// page lives solely in DepartmentPageDefaultService.
Route::middleware(['auth:sanctum', 'role:super_admin,tech_admin'])->prefix('admin/departments')->group(function () {
    Route::get('/{department}/page-access', [\App\Http\Controllers\Admin\DepartmentPageDefaultController::class, 'show']);
    Route::put('/{department}/page-access', [\App\Http\Controllers\Admin\DepartmentPageDefaultController::class, 'update']);
});

// ─── EMC Unified Tickets & Issue Tracking System Routes ────────────────────
// Every action requires authentication; object-level authorization (creator/
// assignee/department-leader/global) is enforced inside TicketController via
// the canonical DepartmentAccessService — see its class docblock.
Route::middleware('auth:sanctum')->prefix('v1/tickets')->group(function () {
    Route::get('/meta', [TicketController::class, 'meta']);
    Route::get('/', [TicketController::class, 'index']);
    Route::post('/', [TicketController::class, 'store']);
    Route::get('/{id}', [TicketController::class, 'show']);
    Route::post('/{id}/approve', [TicketController::class, 'approve']);
    Route::post('/{id}/reject', [TicketController::class, 'rejectByAdmin']);
    Route::post('/{id}/accept-task', [TicketController::class, 'acceptTask']);
    Route::post('/{id}/reject-task', [TicketController::class, 'rejectTask']);
    Route::post('/{id}/complete-task', [TicketController::class, 'completeTask']);
    Route::post('/{id}/comment', [TicketController::class, 'addComment']);
    Route::post('/{id}/reassign', [TicketController::class, 'reassign']);
    Route::get('/{ticketId}/attachments/{attachmentId}/download', [TicketController::class, 'downloadAttachment'])
        ->name('tickets.attachments.download');
});

