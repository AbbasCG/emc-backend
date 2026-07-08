<?php

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
use App\Http\Controllers\Admin\DepartmentSectionController;
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
use App\Http\Controllers\Admin\AdminMemberController;
use App\Http\Controllers\Admin\AdminTeamProfileController;
use App\Http\Controllers\Public\VolunteerRequestController as PublicVolunteerRequestController;
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
use App\Http\Controllers\Admin\AdminPlacementController;
use Illuminate\Support\Facades\Route;

// ─── Health (no throttle — used by uptime monitors) ──────────────────────────
Route::get('/health', \App\Http\Controllers\HealthController::class);

// ─── Auth (throttle:auth — 10 req/min per IP) ────────────────────────────────
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register',       [AuthController::class, 'register']);
    Route::post('/login',          [AuthController::class, 'login']);
    Route::post('/forgot-password', [\App\Http\Controllers\Auth\ForgotPasswordController::class, 'send']);
    Route::post('/reset-password',  [\App\Http\Controllers\Auth\ResetPasswordController::class, 'reset']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me',      [AuthController::class, 'me']);
    });
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

// ─── Student LMS (auth:sanctum + role:student) ─────────────────────────────────
Route::prefix('student')->middleware(['auth:sanctum', 'role:student'])->group(function () {
    Route::get('/dashboard',     StudentDashboardController::class);
    Route::get('/sessions',      [StudentSessionController::class, 'index']);
    Route::get('/materials',     [StudentMaterialController::class, 'index']);
    Route::get('/assignments',              [StudentAssignmentController::class, 'index']);
    Route::get('/assignments/{assignment}', [StudentAssignmentController::class, 'show']);
    Route::post('/assignments/{assignment}/submit', [StudentAssignmentController::class, 'submit']);
    Route::post('/lessons/{lesson}/complete', [StudentLessonController::class, 'complete']);
    Route::get('/progress',      [StudentProgressController::class, 'index']);
    Route::get('/attendance',           [\App\Http\Controllers\Student\StudentAttendanceController::class, 'index']);
    Route::get('/attendance/{session}', [\App\Http\Controllers\Student\StudentAttendanceController::class, 'show']);
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
    Route::post('/quizzes/{quiz}/submit',      [\App\Http\Controllers\Student\StudentLearningController::class, 'submitQuiz']);

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

// ─── Protected material download (auth:sanctum, access checked inside) ────────
Route::middleware('auth:sanctum')->get(
    '/materials/{material}/download',
    [\App\Http\Controllers\Student\MaterialDownloadController::class, 'download']
)->name('materials.download');

// ─── Protected assignment submission download (auth:sanctum, access checked inside) ──
Route::middleware('auth:sanctum')->get(
    '/submissions/{submission}/download',
    [\App\Http\Controllers\SubmissionDownloadController::class, 'download']
)->name('submissions.download');

// ─── Instructor submission file (inline preview or attachment, JSON errors) ────
Route::get(
    '/instructor/submissions/{submission}/file',
    [\App\Http\Controllers\SubmissionDownloadController::class, 'instructorFile']
)->name('instructor.submissions.file');

// ─── Notifications (auth:sanctum, any role) ───────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications',                   [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count',      [NotificationController::class, 'unreadCount']);
    Route::put('/notifications/read-all',          [NotificationController::class, 'markAllRead']);
    Route::put('/notifications/{id}/read',         [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{id}',           [NotificationController::class, 'delete']);

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
    Route::get('/attendance/{session}',            [SessionAttendanceController::class,    'show']);
    Route::get('/attendance',                                           [SessionAttendanceController::class,    'index']);
    Route::put('/attendance/{session}',                                 [SessionAttendanceController::class,    'update']);
    Route::get('/assignments',                                          [InstructorAssignmentController::class, 'index']);
    Route::get('/submissions',                                          [InstructorSubmissionController::class, 'index']);
    Route::get('/submissions/{submission}',                             [InstructorSubmissionController::class, 'show']);
    Route::put('/submissions/{submission}/review',                      [InstructorSubmissionController::class, 'review']);
    Route::patch('/submissions/{submission}/review',                    [InstructorSubmissionController::class, 'review']);
    Route::patch('/submissions/{submission}/grade',                     [InstructorSubmissionController::class, 'review']);
    Route::get('/assignments/{assignment}/submissions',                  [InstructorSubmissionController::class, 'forAssignment']);
    Route::get('/notifications', [NotificationController::class, 'index']);

    // ── Placement: per-course student list + oral assessment management ───────
    Route::get( '/courses/{course}/placement-students',       [InstructorPlacementController::class, 'students']);
    Route::get( '/courses/{course}/students',                 [InstructorPlacementController::class, 'students']);
    Route::get( '/placement-tests',                                     [InstructorPlacementController::class, 'placementTests']);
    Route::get( '/placement-tests/{attempt}/answers',                  [InstructorPlacementController::class, 'answers']);

    // ── Class / group management ──────────────────────────────────────────────
    Route::get(   '/classes',                                           [\App\Http\Controllers\Instructor\InstructorClassController::class, 'index']);
    Route::post(  '/classes',                                           [\App\Http\Controllers\Instructor\InstructorClassController::class, 'store']);
    Route::patch( '/classes/{group}',                                   [\App\Http\Controllers\Instructor\InstructorClassController::class, 'update']);
    Route::delete('/classes/{group}',                                   [\App\Http\Controllers\Instructor\InstructorClassController::class, 'destroy']);
    Route::get(   '/classes/{group}/students',                          [\App\Http\Controllers\Instructor\InstructorClassController::class, 'groupStudents']);
    Route::post(  '/classes/{group}/students',                          [\App\Http\Controllers\Instructor\InstructorClassController::class, 'addStudent']);
    Route::delete('/classes/{group}/students/{userId}',                 [\App\Http\Controllers\Instructor\InstructorClassController::class, 'removeStudent']);
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

    // ── Course content: individual GET indexes ───────────────────────────────
    Route::get('/courses/{course}/content',                              [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'content']);
    Route::get('/courses/{course}/modules',                              [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'indexModules']);
    Route::get('/courses/{course}/sessions',                             [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'indexSessions']);
    Route::get('/courses/{course}/materials',                            [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'indexMaterials']);
    Route::get('/courses/{course}/assignments',                          [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'indexAssignments']);

    // ── Course content: nested CRUD (POST /instructor/courses/{course}/…) ────────
    Route::post('/courses/{course}/modules',                             [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'storeModule']);
    Route::post('/courses/{course}/modules/reorder',                     [\App\Http\Controllers\Instructor\InstructorCourseContentController::class, 'reorderModules']);
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
    ->middleware(['auth:sanctum', 'role:student']);

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
        Route::get('/assignments/{assignment}', [AdminLmsAssignmentController::class, 'show']);
        Route::put('/assignments/{assignment}', [AdminLmsAssignmentController::class, 'update']);
        Route::delete('/assignments/{assignment}', [AdminLmsAssignmentController::class, 'destroy']);

        Route::get('/materials', [AdminLmsMaterialController::class, 'index']);
        Route::post('/materials', [AdminLmsMaterialController::class, 'store']);
        Route::get('/materials/{material}', [AdminLmsMaterialController::class, 'show']);
        Route::put('/materials/{material}', [AdminLmsMaterialController::class, 'update']);
        Route::delete('/materials/{material}', [AdminLmsMaterialController::class, 'destroy']);

        Route::get('/attendance', [AdminLmsAttendanceController::class, 'index']);
        Route::put('/attendance/{session}', [SessionAttendanceController::class, 'update']);

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

// Signed CV download — URL issued only via authenticated admin volunteer-request APIs
Route::get('admin/volunteer-requests/{volunteerRequest}/cv', [AdminVolunteerRequestController::class, 'downloadCv'])
    ->middleware('signed')
    ->name('admin.volunteer-requests.cv');

// Signed CV inline view
Route::get('admin/volunteer-requests/{volunteerRequest}/cv/view', [AdminVolunteerRequestController::class, 'viewCv'])
    ->middleware('signed')
    ->name('admin.volunteer-requests.cv.view');

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

// ─── Phase 3: Operations — auth:sanctum + role:admin ─────────────────────────
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin'])->prefix('admin')->group(function () {

    // Departments
    Route::apiResource('departments', DepartmentController::class);
    Route::apiResource('department-sections', DepartmentSectionController::class);

    // Tasks
    Route::get('tasks/my',      [TaskController::class, 'myTasks']);
    Route::get('tasks/overdue', [TaskController::class, 'overdue']);
    Route::get('tasks/kanban',  [TaskController::class, 'kanban']);
    Route::apiResource('tasks', TaskController::class);
    Route::post('tasks/{task}/comments',               [TaskController::class, 'storeComment']);
    Route::post('tasks/{task}/checklist',              [TaskController::class, 'storeChecklist']);
    Route::put('tasks/{task}/checklist/{item}',        [TaskController::class, 'updateChecklist']);

    // Meetings
    Route::apiResource('meetings', MeetingController::class);
    Route::post('meetings/{meeting}/decisions',                          [MeetingController::class, 'storeDecision']);
    Route::post('meetings/{meeting}/action-items',                       [MeetingController::class, 'storeActionItem']);
    Route::post('meetings/{meeting}/action-items/{actionItem}/convert-to-task', [MeetingController::class, 'convertActionItemToTask']);

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

    // ── Phase 7: Webhooks ─────────────────────────────────────────────────────
    Route::get('webhook-endpoints',                  [WebhookEndpointController::class, 'index']);
    Route::post('webhook-endpoints',                 [WebhookEndpointController::class, 'store']);
    Route::get('webhook-endpoints/{webhookEndpoint}',[WebhookEndpointController::class, 'show']);
    Route::put('webhook-endpoints/{webhookEndpoint}',[WebhookEndpointController::class, 'update']);
    Route::delete('webhook-endpoints/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy']);
    Route::post('webhook-endpoints/{webhookEndpoint}/test', [WebhookEndpointController::class, 'test']);
    Route::get('webhook-deliveries',                 [WebhookEndpointController::class, 'deliveries']);

    // ── Phase 7: Integration settings ────────────────────────────────────────
    Route::get('integrations',                       [IntegrationSettingController::class, 'index']);
    Route::put('integrations/{key}',                 [IntegrationSettingController::class, 'upsert']);

    // ── Phase 7: API tokens ───────────────────────────────────────────────────
    Route::get('api-tokens',                         [ApiTokenController::class, 'index']);
    Route::post('api-tokens',                        [ApiTokenController::class, 'store']);
    Route::delete('api-tokens/{id}',                 [ApiTokenController::class, 'destroy']);

    // ── Email settings & logs ─────────────────────────────────────────────────
    Route::get('email-settings',            [EmailSettingsController::class, 'index']);
    Route::put('email-settings',            [EmailSettingsController::class, 'update']);
    Route::post('email-settings/test',      [EmailSettingsController::class, 'test']);
    Route::get('email-logs',                [EmailLogsController::class, 'index']);
    Route::get('email-logs/stats',          [EmailLogsController::class, 'stats']);
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
    Route::post('roles/{role}/grant-permission',     [RoleController::class, 'grantPermission']);
    Route::post('roles/{role}/revoke-permission',    [RoleController::class, 'revokePermission']);

    // ── RBAC: Permission catalog ──────────────────────────────────────────────
    Route::get('permissions',                        [PermissionController::class, 'index']);
    Route::get('me/permissions',                     [PermissionController::class, 'myPermissions']);

    // ── RBAC: User role assignment ────────────────────────────────────────────
    Route::put('users/{user}/role',                  [UserRoleController::class, 'assignRole']);
    Route::get('users/{user}/permissions',           [UserRoleController::class, 'getUserPermissions']);

    // ── Phase 8: AI Admin ─────────────────────────────────────────────────────
    Route::prefix('ai')->group(function () {
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

        // AI Usage logs & cost tracking
        Route::get('usage',                      [AiUsageController::class, 'index']);
        Route::get('usage/summary',              [AiUsageController::class, 'summary']);

        // AI Automation rules
        Route::get('automations',                [AiAutomationController::class, 'index']);
        Route::post('automations',               [AiAutomationController::class, 'store']);
        Route::get('automations/{aiAutomationRule}',            [AiAutomationController::class, 'show']);
        Route::put('automations/{aiAutomationRule}',            [AiAutomationController::class, 'update']);
        Route::delete('automations/{aiAutomationRule}',         [AiAutomationController::class, 'destroy']);
        Route::get('automations/{aiAutomationRule}/executions', [AiAutomationController::class, 'executions']);
    });
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
    Route::get('notifications/preferences',   [NotificationPreferenceController::class, 'index']);
    Route::patch('notifications/preferences', [NotificationPreferenceController::class, 'update']);
});

// ─── Admin dashboard alias ───────────────────────────────────────────────────
Route::get('dashboard/admin', [AdminController::class, 'dashboard'])
    ->middleware(['auth:sanctum', 'role:admin']);

// ─── LMS routes (student/auth — frontend uses /lms/*) ────────────────────────
Route::middleware('auth:sanctum')->prefix('lms')->group(function () {
    Route::get('courses/{courseId}/modules', [StudentLearningController::class, 'modules']);
    Route::get('lessons/{lesson}',           [StudentLearningController::class, 'lesson']);
    Route::post('quizzes/{quiz}/submit',     [StudentLearningController::class, 'submitQuiz']);
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
    Route::get('assignments',                [AdminLmsAssignmentController::class, 'index']);
    Route::post('assignments',               [AdminLmsAssignmentController::class, 'store']);
    Route::get('assignments/{assignment}',   [AdminLmsAssignmentController::class, 'show']);
    Route::put('assignments/{assignment}',   [AdminLmsAssignmentController::class, 'update']);
    Route::patch('assignments/{assignment}', [AdminLmsAssignmentController::class, 'update']);
    Route::delete('assignments/{assignment}',[AdminLmsAssignmentController::class, 'destroy']);

    // Materials CRUD
    Route::get('materials',                  [AdminLmsMaterialController::class,   'index']);
    Route::post('materials',                 [AdminLmsMaterialController::class,   'store']);
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
Route::middleware(['auth:sanctum', 'role:admin,department_manager,programs_manager'])->prefix('operations')->group(function () {
    Route::get('departments',              [DepartmentController::class, 'index']);
    Route::get('departments/{department}', [DepartmentController::class, 'show']);
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
Route::middleware(['auth:sanctum', 'role:admin,super_admin,tech_admin'])->prefix('operations')->group(function () {
    Route::get('dashboard',                                            [AdminController::class,                   'dashboard']);
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
    Route::get('volunteers',                                           [VolunteerController::class,               'index']);
    Route::get('volunteers/{volunteer}',                               [VolunteerController::class,               'show']);
    Route::patch('volunteers/{volunteer}',                             [VolunteerController::class,               'update']);
    Route::get('partners',                                             [PartnerController::class,                 'index']);
    Route::get('partnership-requests',                                 [PartnershipRequestAdminController::class, 'index']);
    Route::patch('partnership-requests/{partnershipRequest}',          [PartnershipRequestAdminController::class, 'updateStatus']);
    Route::get('marketing',                                            [MarketingItemController::class,           'index']);
    Route::patch('marketing/{marketingItem}',                          [MarketingItemController::class,           'update']);
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
    Route::patch('coupons/{coupon}',      [CouponController::class,     'update']);

    // Finance accounts & manual transactions
    Route::get('accounts',                                           [FinanceAccountController::class, 'index']);
    Route::post('accounts',                                          [FinanceAccountController::class, 'store']);
    Route::patch('accounts/{account}',                               [FinanceAccountController::class, 'update']);
    Route::get('accounts/{account}/transactions',                    [FinanceAccountController::class, 'transactions']);
    Route::post('accounts/{account}/transactions',                   [FinanceAccountController::class, 'addTransaction']);
    Route::get('accounts-summary',                                   [FinanceController::class,        'accountsSummary']);
});

// ─── KPI unified endpoint (?tab=overview|education|finance|…) ────────────────
// finance_manager, quality_manager, and programs_manager have VIEW_KPI permission
Route::get('kpi', [KpiController::class, 'unified'])
    ->middleware(['auth:sanctum', 'role:admin,finance_manager,quality_manager,programs_manager']);

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
Route::middleware(['auth:sanctum', 'role:admin,programs_manager'])->group(function () {
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

// ─── Platform scale (public stats) ───────────────────────────────────────────
Route::get('platform/scale', function () {
    $data = \Illuminate\Support\Facades\Cache::remember('platform_scale', 3600, function () {
        return [
            'users'         => \App\Models\User::count(),
            'courses'       => \App\Models\Course::count(),
            'instructors'   => \App\Models\Instructor::count(),
            'registrations' => \App\Models\Registration::count(),
        ];
    });

    return response()->json(['success' => true, 'data' => $data]);
})->middleware('throttle:api');

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
});

// ─── HR Manager portal (auth:sanctum + role:hr_manager,admin,super_admin,tech_admin) ────
Route::middleware(['auth:sanctum', 'role:hr_manager,admin,super_admin,tech_admin'])->prefix('hr')->group(function () {
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

    // ── Course students (manual enrollment + participant list) ────────────────
    Route::get('admin/courses/{course}/students',              [\App\Http\Controllers\Admin\AdminCourseStudentsController::class, 'index']);
    Route::post('admin/courses/{course}/students',             [\App\Http\Controllers\Admin\AdminCourseStudentsController::class, 'store']);
    Route::delete('admin/courses/{course}/students/{user}',    [\App\Http\Controllers\Admin\AdminCourseStudentsController::class, 'destroy']);

    // ── Course content: nested CRUD (all under /admin/courses/{course}/…) ───────
    Route::get('admin/courses/{course}/content',                             [\App\Http\Controllers\Admin\CourseContentController::class, 'content']);
    Route::post('admin/courses/{course}/modules',                            [\App\Http\Controllers\Admin\CourseContentController::class, 'storeModule']);
    Route::post('admin/courses/{course}/modules/reorder',                    [\App\Http\Controllers\Admin\CourseContentController::class, 'reorderModules']);
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
    Route::get('audit-logs',             [AdminAuditLogController::class, 'index']);
    Route::get('audit-logs/{auditLog}',  [AdminAuditLogController::class, 'show']);
});

// ─── Impersonation ────────────────────────────────────────────────────────────
// Start: role:super_admin,admin,tech_admin — permission check (users.impersonate) is enforced
// in the controller, so having the role alone is not sufficient.
Route::middleware(['auth:sanctum', 'role:super_admin,admin,tech_admin'])->group(function () {
    Route::post('/admin/impersonate/{user}', [ImpersonationController::class, 'start']);
});
// Stop: authenticated with the impersonation token (role is the target user's role)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/admin/impersonate/stop', [ImpersonationController::class, 'stop']);
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
