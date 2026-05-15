<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AdminLmsAssignmentController;
use App\Http\Controllers\Admin\AdminPartnerPortalController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AutomationRuleController;
use App\Http\Controllers\Admin\CertificateController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CourseModuleController;
use App\Http\Controllers\Admin\DocumentFolderController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\KnowledgeArticleController;
use App\Http\Controllers\Admin\KnowledgeCategoryController;
use App\Http\Controllers\Admin\KpiController;
use App\Http\Controllers\Admin\LessonController;
use App\Http\Controllers\Admin\QuizController;
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
use App\Http\Controllers\Admin\DynamicFormController;
use App\Http\Controllers\Admin\MarketingItemController;
use App\Http\Controllers\Admin\MeetingController;
use App\Http\Controllers\Admin\PartnerController;
use App\Http\Controllers\Admin\PartnershipRequestAdminController;
use App\Http\Controllers\Admin\SupportTicketController as AdminSupportTicketController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\VolunteerController;
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
use Illuminate\Support\Facades\Route;

// ─── Health (no throttle — used by uptime monitors) ──────────────────────────
Route::get('/health', function () {
    return response()->json([
        'success'   => true,
        'message'   => 'EMC API is healthy',
        'data'      => [
            'status'    => 'ok',
            'timestamp' => now()->toIso8601String(),
            'version'   => '1.0.0',
        ],
    ]);
});

// ─── Auth (throttle:auth — 10 req/min per IP) ────────────────────────────────
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me',      [AuthController::class, 'me']);
    });
});

// ─── Public: Certificate verification ────────────────────────────────────────
Route::get('/certificates/verify/{code}', [CertificateVerificationController::class, 'verify']);

// ─── Public: Knowledge base (published + public articles) ────────────────────
Route::get('/knowledge',        [KnowledgeController::class, 'index']);
Route::get('/knowledge/{slug}', [KnowledgeController::class, 'show']);

// ─── Global search (auth optional — results filtered by role) ─────────────────
Route::get('/search', SearchController::class)->middleware('throttle:api');

// ─── Public: Team (EMC V3 website team page — no auth required) ──────────────
Route::get('/team', [\App\Http\Controllers\TeamController::class, 'index'])->middleware('throttle:api');

// ─── Student LMS (auth:sanctum + role:student) ─────────────────────────────────
Route::prefix('student')->middleware(['auth:sanctum', 'role:student'])->group(function () {
    Route::get('/dashboard', StudentDashboardController::class);
    Route::get('/sessions', [StudentSessionController::class, 'index']);
    Route::get('/materials', [StudentMaterialController::class, 'index']);
    Route::get('/assignments', [StudentAssignmentController::class, 'index']);
    Route::post('/assignments/{assignment}/submit', [StudentAssignmentController::class, 'submit']);
    Route::get('/progress', [StudentProgressController::class, 'index']);
    Route::post('/evaluations', [StudentEvaluationController::class, 'store']);
    Route::get('/certificates', [StudentCertificateController::class, 'index']);

    // Advanced LMS
    Route::get('/learning',                          [StudentLearningController::class, 'index']);
    Route::get('/courses/{courseId}/modules',        [StudentLearningController::class, 'modules']);
    Route::get('/lessons/{lesson}',                  [StudentLearningController::class, 'lesson']);
    Route::post('/quizzes/{quiz}/submit',            [StudentLearningController::class, 'submitQuiz']);
});

// ─── Notifications (auth:sanctum, any role) ───────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications',                   [NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read',         [NotificationController::class, 'markRead']);
    Route::put('/notifications/read-all',          [NotificationController::class, 'markAllRead']);

    // Documents (auth:sanctum, any role; visibility enforced in controller)
    Route::get('/documents',  [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);

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
});

// ─── Instructor LMS portal (auth:sanctum + role:teacher,admin) ──────────────
Route::prefix('instructor')->middleware(['auth:sanctum', 'role:teacher,admin'])->group(function () {
    Route::get('/dashboard', InstructorDashboardController::class);
    Route::get('/sessions', [InstructorSessionController::class, 'index']);
    Route::get('/courses', [InstructorCourseController::class, 'index']);
    Route::get('/students', [InstructorStudentController::class, 'index']);
    Route::put('/attendance/{session}', [SessionAttendanceController::class, 'update']);
    Route::get('/assignments', [InstructorAssignmentController::class, 'index']);
    Route::put('/submissions/{submission}/review', [InstructorSubmissionController::class, 'review']);
});

// ─── Public: Tracks (LMS) ────────────────────────────────────────────────────
Route::get('/tracks',       [TrackController::class, 'index']);
Route::get('/tracks/{slug}', [TrackController::class, 'show']);

// ─── Public: Courses ─────────────────────────────────────────────────────────
Route::get('/courses',        [CourseController::class, 'index']);
Route::get('/courses/{slug}', [CourseController::class, 'show']);

// ─── Public: Instructors ─────────────────────────────────────────────────────
Route::get('/instructors',       [InstructorController::class, 'index']);
Route::get('/instructors/{id}',  [InstructorController::class, 'show']);

// ─── Course Registration (throttle:registration — 5 req/min per IP) ──────────
Route::post('/register', [RegistrationController::class, 'store'])
    ->middleware('throttle:registration');

// ─── Workshop Request Submission (throttle:workshop — 5 req/min per IP) ──────
Route::post('/workshop-requests', [WorkshopRequestController::class, 'store'])
    ->middleware('throttle:workshop');

// ─── Payment Webhooks (unauthenticated — called by Stripe/PayPal servers) ─────
// No throttle — webhook retry logic must not be blocked
Route::post('/payments/stripe/webhook', [PaymentController::class, 'stripeWebhook'])->name('payments.stripe.webhook');
Route::post('/payments/paypal/webhook', [PaymentController::class, 'paypalWebhook'])->name('payments.paypal.webhook');

// Fake payment endpoints — FakePaymentEnvironment enforced in controller
Route::post('/payments/fake/confirm/{payment}', [PaymentController::class, 'confirmFakePayment'])->name('payments.fake.confirm');
Route::post('/payments/fake/fail/{payment}',    [PaymentController::class, 'failFakePayment'])->name('payments.fake.fail');

// ─── Admin: auth:sanctum + role:admin ────────────────────────────────────────
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {

    Route::get('/workshop-requests', [WorkshopRequestController::class, 'index']);

    Route::prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);

        Route::get('/registrations', [AdminController::class, 'registrations']);
        Route::get('/registrations/{registration}', [AdminController::class, 'showRegistration']);
        Route::put('/registrations/{registration}/status', [AdminController::class, 'updateRegistrationStatus']);
        Route::patch('/registrations/{registration}/status', [AdminController::class, 'updateRegistrationStatus']);

        Route::get('/payments',    [AdminPaymentController::class, 'index']);
        Route::get('/payments/{payment}', [AdminPaymentController::class, 'show']);

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

// ─── Public: Support tickets ─────────────────────────────────────────────────
Route::post('/support-tickets', [PublicSupportTicketController::class, 'store'])
    ->middleware('throttle:workshop');

// ─── Phase 3: Operations — auth:sanctum + role:admin ─────────────────────────
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {

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

    // ── Phase 4: Certificates ─────────────────────────────────────────────────
    Route::get('certificates',                      [CertificateController::class, 'index']);
    Route::post('certificates',                     [CertificateController::class, 'store']);
    Route::get('certificates/{certificate}',        [CertificateController::class, 'show']);
    Route::put('certificates/{certificate}/approve',[CertificateController::class, 'approve']);
    Route::put('certificates/{certificate}/issue',  [CertificateController::class, 'issue']);
    Route::put('certificates/{certificate}/revoke', [CertificateController::class, 'revoke']);

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
    Route::get('events',                             [CalendarController::class, 'index']);
    Route::get('events/{calendarEvent}/ics',         [CalendarController::class, 'downloadIcs']);
});

// ─── Phase 7: Notification preferences (auth required) ───────────────────────
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('notification-preferences',           [NotificationPreferenceController::class, 'index']);
    Route::put('notification-preferences',           [NotificationPreferenceController::class, 'update']);
});
