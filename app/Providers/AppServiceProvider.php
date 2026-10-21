<?php

namespace App\Providers;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\Document;
use App\Models\DynamicForm;
use App\Models\Evaluation;
use App\Models\KnowledgeArticle;
use App\Models\LearningPath;
use App\Models\LmsSession;
use App\Models\Meeting;
use App\Models\MeetingActionItem;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\Report;
use App\Models\StudentProgress;
use App\Models\Task;
use App\Models\Track;
use App\Models\VolunteerProfile;
use App\Models\Workshop;
use App\Models\ManualPayment;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
// Event/Listener imports removed — Laravel 13 auto-discovers all classes in
// app/Listeners via the Dispatchable / ShouldQueue convention.
// Manually calling Event::listen() here would double-register every listener.
use App\Policies\AssignmentPolicy;
use App\Policies\AssignmentSubmissionPolicy;
use App\Policies\CertificatePolicy;
use App\Policies\CourseMaterialPolicy;
use App\Policies\CoursePolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\EvaluationPolicy;
use App\Policies\KnowledgeArticlePolicy;
use App\Policies\LearningPathPolicy;
use App\Policies\LmsSessionPolicy;
use App\Policies\MeetingPolicy;
use App\Policies\PartnerPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\RegistrationPolicy;
use App\Policies\ReportPolicy;
use App\Policies\StudentProgressPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TrackPolicy;
use App\Policies\UserPolicy;
use App\Policies\WorkshopPolicy;
use App\Policies\ManualPaymentPolicy;
use App\Policies\ProductUpdatePolicy;
use App\Models\ProductUpdate;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind AiManager as a singleton so the same provider instance is reused
        $this->app->singleton(\App\AI\AiManager::class);

        // Bind KnowledgeRetrievalService (depends on AiManager singleton)
        $this->app->singleton(\App\AI\Retrieval\KnowledgeRetrievalService::class, function ($app) {
            return new \App\AI\Retrieval\KnowledgeRetrievalService($app->make(\App\AI\AiManager::class));
        });

        // Bind AI services
        $this->app->singleton(\App\AI\Services\AiConversationService::class, function ($app) {
            $registry = new \App\AI\Tools\ToolRegistry();
            $registry->register(new \App\AI\Tools\GetStudentProgressTool());
            $registry->register(new \App\AI\Tools\SearchKnowledgeTool());
            $registry->register(new \App\AI\Tools\GetKpiMetricsTool());
            $registry->register(new \App\AI\Tools\CreateTaskTool());
            $registry->register(new \App\AI\Tools\GetUpcomingSessionsTool());

            return new \App\AI\Services\AiConversationService($app->make(\App\AI\AiManager::class), $registry);
        });

        $this->app->singleton(\App\AI\Services\AiContentGenerationService::class, function ($app) {
            return new \App\AI\Services\AiContentGenerationService($app->make(\App\AI\AiManager::class));
        });

        $this->app->singleton(\App\AI\Services\AiMeetingService::class, function ($app) {
            return new \App\AI\Services\AiMeetingService($app->make(\App\AI\AiManager::class));
        });

        $this->app->singleton(\App\AI\Services\AiInsightService::class, function ($app) {
            return new \App\AI\Services\AiInsightService($app->make(\App\AI\AiManager::class));
        });

        // Bound (not `new`'d ad hoc) so tests can swap in a fake without a
        // real Stripe network call — see StripePaymentMethodResolver.
        $this->app->singleton(\Stripe\StripeClient::class, function () {
            return new \Stripe\StripeClient((string) config('services.stripe.secret'));
        });
        $this->app->singleton(\App\Services\Payments\StripePaymentMethodResolver::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureLmsRouteModelBinding();
        $this->configurePolicies();
        $this->configurePermissionGates();
        // configureEventListeners() intentionally removed.
        // Laravel 13 auto-discovers every handle(SomeEvent) listener in app/Listeners.
        // Adding Event::listen() calls here on top of auto-discovery registers each
        // listener TWICE — one notification fires → user receives two duplicates.
    }

    private function configureLmsRouteModelBinding(): void
    {
        // {session} resolves to an LmsSession first; if not found, falls back to
        // CourseSession (instructors use both types and attendance supports both).
        Route::bind('session', function ($value) {
            return \App\Models\LmsSession::query()->whereKey($value)->first()
                ?? \App\Models\CourseSession::query()->whereKey($value)->firstOrFail();
        });

        Route::bind('form', fn ($value) => DynamicForm::query()->whereKey($value)->firstOrFail());
        Route::bind('volunteer', fn ($value) => VolunteerProfile::query()->whereKey($value)->firstOrFail());
        Route::bind('actionItem', fn ($value) => MeetingActionItem::query()->whereKey($value)->firstOrFail());

        Route::bind('assignment', function ($value) {
            return app(\App\Services\CourseAssignmentBridgeService::class)
                ->resolveAssignment((int) $value)
                ?? abort(404, 'Assignment not found.');
        });
    }

    private function configurePolicies(): void
    {
        // super_admin bypasses all Gates and policies EXCEPT users.impersonate,
        // which requires an explicit DB-granted permission even for super_admin.
        Gate::before(function (User $user, string $ability) {
            if (in_array($ability, [Permissions::IMPERSONATE_USERS, Permissions::EXECUTIVE_COMMAND_CENTER_VIEW], true)) {
                return null; // fall through to the registered Gate definition
            }
            // super_admin and tech_admin bypass all Gates.
            // executive_admin is intentionally excluded — it is read-only.
            if ($user->isSuperAdmin() || $user->isTechAdmin()) {
                return true;
            }

            return null;
        });

        // Phase 2 LMS policies
        Gate::policy(LmsSession::class, LmsSessionPolicy::class);
        Gate::policy(Assignment::class, AssignmentPolicy::class);
        Gate::policy(AssignmentSubmission::class, AssignmentSubmissionPolicy::class);
        Gate::policy(CourseMaterial::class, CourseMaterialPolicy::class);
        Gate::policy(\App\Models\ClassGroup::class, \App\Policies\ClassGroupPolicy::class);
        Gate::policy(Evaluation::class, EvaluationPolicy::class);
        Gate::policy(StudentProgress::class, StudentProgressPolicy::class);

        // Phase 6 security policies
        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(Workshop::class, WorkshopPolicy::class);
        Gate::policy(Track::class, TrackPolicy::class);
        Gate::policy(Registration::class, RegistrationPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Certificate::class, CertificatePolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(Meeting::class, MeetingPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(KnowledgeArticle::class, KnowledgeArticlePolicy::class);
        Gate::policy(Partner::class, PartnerPolicy::class);
        Gate::policy(Report::class, ReportPolicy::class);

        // Phase RBAC new policies
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(LearningPath::class, LearningPathPolicy::class);

        // Finance manual payments
        Gate::policy(ManualPayment::class, ManualPaymentPolicy::class);
        Gate::policy(\App\Models\ManualExpense::class, \App\Policies\ManualExpensePolicy::class);
        Gate::policy(\App\Models\ManualRevenue::class, \App\Policies\ManualRevenuePolicy::class);
        Gate::policy(ProductUpdate::class, ProductUpdatePolicy::class);

        // Dynamic forms: form-level and department-level authorization.
        Gate::policy(DynamicForm::class, \App\Policies\DynamicFormPolicy::class);
    }

    /**
     * Register every permission constant as a named Gate.
     *
     * Usage: Gate::authorize(Permissions::MANAGE_COURSES)
     *   or:  $user->can('manage_courses')
     *
     * super_admin is handled by Gate::before() in configurePolicies(),
     * so we only need to handle the general case here.
     */
    private function configurePermissionGates(): void
    {
        foreach (Permissions::all() as $permission) {
            Gate::define($permission, fn ($user) => $user->hasPermission($permission));
        }

        // Register gated permissions that are intentionally excluded from all()
        // so they are never auto-granted. Gate::before bypass is also skipped for these.
        Gate::define(Permissions::IMPERSONATE_USERS, fn (User $user) => $user->hasPermission(Permissions::IMPERSONATE_USERS));
        Gate::define(Permissions::EXECUTIVE_COMMAND_CENTER_VIEW, fn (User $user) => $user->hasPermission(Permissions::EXECUTIVE_COMMAND_CENTER_VIEW));
    }

    private function configureRateLimiting(): void
    {
        // General API — 60 req/min per authenticated user or IP
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip());
        });

        // Auth endpoints — 5 req/min per IP (brute-force protection)
        // Covers login, forgot-password, reset-password.
        // 5 attempts per minute is sufficient for legitimate users and blocks credential stuffing.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Course registration — 5 req/min per IP
        RateLimiter::for('registration', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Workshop / partnership / support form submissions — 5 req/min per IP
        RateLimiter::for('public_forms', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Workshop request submissions (legacy named limiter kept for backward compat)
        RateLimiter::for('workshop', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Public form submissions — per form per IP, plus an hourly ceiling per IP. Separate from the general api
        // bucket so reading forms never uses up the submission allowance (and a flood of submissions never blocks reads).
        RateLimiter::for('form_submissions', function (Request $request) {
            return [
                Limit::perMinute(10)->by('form-submit:'.$request->route('slug').'|'.$request->ip()),
                Limit::perHour(60)->by('form-submit-ip:'.$request->ip()),
            ];
        });

        // Contact form — 3 req/min per IP
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        // Payment write actions — 10 req/min per user
        RateLimiter::for('payments', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip());
        });

        // Admin write actions — 30 req/min per admin user
        RateLimiter::for('admin_write', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip());
        });

        // AI endpoints — configurable per-user limit (default 20 req/min)
        RateLimiter::for('ai', function (Request $request) {
            return Limit::perMinute(config('ai.rate_limit_per_minute', 20))
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}
