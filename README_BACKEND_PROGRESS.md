# EMC Backend — Development Progress Log

> **Platform:** Arabic-first Educational SaaS / LMS
> **Stack:** Laravel 13 · PHP 8.3 · MySQL · Laravel Sanctum · Stripe · PayPal
> **Audit baseline score:** 3.5 / 10

---

## PHASE 1 — Critical Bug Fixes & Safety Foundation

**Date:** 2026-05-10
**Status:** ✅ COMPLETE

### What Was Changed

#### 1. Fixed — Course model/migration mismatch
**File:** `app/Models/Course.php`

- **Removed** four non-existent fillable fields that had no matching DB columns and caused
  `Unknown column` errors on course creation: `instructor_name`, `instructor_title`,
  `instructor_bio`, `instructor_image`.
- **Added** `instructor_id` to `$fillable` — it existed in the migration FK but was
  missing from the fillable array, preventing mass assignment of the relationship.
- The canonical instructor data lives in the `instructors` table via the
  `instructor_id` foreign key. No data is duplicated on the `courses` table.

#### 2. Fixed — Missing `WorkshopRequestController@index`
**File:** `app/Http/Controllers/WorkshopRequestController.php`

- The route `GET /api/workshop-requests` existed but the controller method did not,
  causing a fatal `BadMethodCallException` (500) on every call.
- Implemented a paginated `index` method:
  - `per_page` query param (default 15, max 100)
  - Optional `status` filter
  - Returns consistent `{ success, message, data }` envelope
- Also wrapped the `store` response in the same consistent envelope.
- **TODO Phase 3:** protect this endpoint with `auth:sanctum + role:admin` middleware.

#### 3. Fixed — Slug collision bug in `CourseController@store`
**File:** `app/Http/Controllers/CourseController.php`

- `Str::slug($title)` with no deduplication caused a unique-constraint DB error when
  two courses had the same title.
- Added private `uniqueSlug(string $title): string` helper that appends `-2`, `-3`, …
  until the slug is unique: `course-title`, `course-title-2`, `course-title-3`.
- Removed `instructor_name` from the validation rules (field has no DB column).
- Added missing validations: `price min:0`, `capacity min:1`, `end_date after_or_equal:start_date`,
  `status in:active,draft,archived`, `training_hours min:0`.
- Explicitly unsets `features` from `$validated` before `Course::create()` to prevent
  accidental mass-assignment of the array.
- `store` response now uses the consistent `{ success, message, data }` envelope.
- `index` and `show` response format is **intentionally left unchanged** for frontend
  compatibility — Phase 4 will wrap them in `CourseResource`.

#### 4. Fixed — InstructorController 200 vs 201 confusion
**File:** `app/Http/Controllers/InstructorController.php`

- `POST /api/instructors` used `firstOrCreate` but always returned HTTP 201 even
  when the instructor already existed.
- Now uses `$instructor->wasRecentlyCreated`:
  - **201** + "Instructor created successfully" — new record
  - **200** + "Instructor already exists" — existing record retrieved
- All three methods (`index`, `show`, `store`) now return the consistent envelope.

#### 5. Fixed — Receipt file upload never stored
**File:** `app/Http/Controllers/RegistrationController.php`

- `receipt` was validated as a file but immediately discarded — never moved to disk
  or saved to the database. The `payments.receipt_url` column existed but was always NULL.
- Receipt file is now stored to the `public` disk under `receipts/` **before** the
  DB transaction opens (file I/O must not be inside DB transactions).
- The stored path is passed into the transaction closure and written to
  `Payment::receipt_url` for both the `fake` and gateway payment branches.
- Added `notes` max length `2000` to prevent unbounded text input.
- All response messages now use the consistent `{ success, message, data }` envelope.
- **Known limitation:** if the DB transaction fails after file upload, the file
  remains on disk (orphaned). A cleanup strategy will be added in a later phase.

#### 6. Added — `GET /api/health` endpoint
**File:** `routes/api.php`

Returns:
```json
{
  "success": true,
  "message": "EMC API is healthy",
  "data": {
    "status": "ok",
    "timestamp": "2026-05-10T...",
    "version": "1.0.0"
  }
}
```

#### 7. Reorganised — `routes/api.php`
**File:** `routes/api.php`

- Added section comments clearly separating Public / Admin / Management route groups.
- Grouped admin routes under `Route::prefix('admin')` for clarity.
- Added inline `TODO Phase 3` comments on every unprotected admin/management endpoint
  so they are impossible to overlook.
- Removed the implicit "test" route intent — marked it for removal before production.
- Route ordering is now: health → public → webhooks → admin → management.

---

### Files Changed

| File | Change |
|------|--------|
| `app/Models/Course.php` | Removed 4 non-existent fillable fields; added `instructor_id` |
| `app/Http/Controllers/WorkshopRequestController.php` | Added `index` method; wrapped responses |
| `app/Http/Controllers/CourseController.php` | Slug deduplication; cleaned validation; consistent response |
| `app/Http/Controllers/InstructorController.php` | Correct HTTP codes; consistent responses |
| `app/Http/Controllers/RegistrationController.php` | Receipt upload stored; consistent responses |
| `routes/api.php` | Added `/health`; reorganised with section comments and TODO markers |
| `README_BACKEND_PROGRESS.md` | Created (this file) |

---

### New Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/health` | API liveness check |
| GET | `/api/workshop-requests` | Paginated workshop request list (⚠️ needs auth — Phase 3) |

---

### Database Changes

None in Phase 1 — all fixes were code-level only.
Migrations are clean and untouched.

---

### Remaining Issues (Pre-Phase 2)

| # | Issue | Severity |
|---|-------|----------|
| 1 | No authentication system (Sanctum not installed) | CRITICAL |
| 2 | Admin routes are fully public — no auth check | CRITICAL |
| 3 | `GET /workshop-requests` returns data without auth | HIGH |
| 4 | `POST /courses` and `POST /instructors` are public | HIGH |
| 5 | PayPal webhook has no signature verification | HIGH |
| 6 | Stripe webhook falls back to unsigned JSON if secret unset | HIGH |
| 7 | No CORS configuration | HIGH |
| 8 | No rate limiting on any endpoint | MEDIUM |
| 9 | No API Resources (raw Eloquent models returned) | MEDIUM |
| 10 | No pagination on `GET /courses`, `GET /instructors` | MEDIUM |
| 11 | No tests | MEDIUM |
| 12 | Orphaned receipt files if DB transaction fails | LOW |

---

### Next Recommended Phase

**PHASE 2 — Authentication System with Sanctum**

- Install `laravel/sanctum`
- Configure API guard in `config/auth.php`
- Add auth endpoints: `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`
- `role` column on `users` table (student / teacher / admin)
- Protect `/auth/me` with `auth:sanctum`
- Form Request validation for register and login

---

## PHASE 2 — Authentication System with Sanctum

**Date:** 2026-05-10
**Status:** ✅ COMPLETE

### What Was Changed

#### 1. Installed — `laravel/sanctum` ^4.3
**Files:** `composer.json`, `composer.lock`, `config/sanctum.php`

- Added `laravel/sanctum` as a production dependency via Composer.
- Published Sanctum config to `config/sanctum.php` (default token expiry settings).
- Published Sanctum migration: `2026_05_10_030151_create_personal_access_tokens_table.php`

#### 2. Added — `role` column to `users` table
**File:** `database/migrations/2026_05_10_100000_add_role_to_users_table.php`

- Added `role` string column with default `'student'` after the `email` column.
- Allowed values (enforced at FormRequest level): `student`, `teacher`, `admin`.
- All existing users inherit the default `student` role.

#### 3. Updated — `User` model
**File:** `app/Models/User.php`

- Added `Laravel\Sanctum\HasApiTokens` trait — enables `createToken()`, `tokens()`,
  `currentAccessToken()` on the User model.
- Switched from PHP attribute-based `#[Fillable]` / `#[Hidden]` to traditional
  `protected $fillable` / `protected $hidden` arrays for broader IDE/tooling support.
- Added `role` to `$fillable`.
- Added three convenience helpers: `isAdmin()`, `isTeacher()`, `isStudent()` — used
  by role middleware in Phase 3.

#### 4. Created — `RegisterRequest` FormRequest
**File:** `app/Http/Requests/Auth/RegisterRequest.php`

- Validates: `name`, `email` (unique), `password` (min 8, confirmed), `role` (optional).
- `role` is limited to `student` or `teacher` on public registration — admin accounts
  must be created via seeder or by an existing admin (Phase 3+).
- All validation messages are in Arabic.

#### 5. Created — `LoginRequest` FormRequest
**File:** `app/Http/Requests/Auth/LoginRequest.php`

- Validates: `email`, `password`.
- Messages in Arabic.

#### 6. Created — `AuthController`
**File:** `app/Http/Controllers/AuthController.php`

| Method | Action |
|--------|--------|
| `register` | Creates user, issues Sanctum token, returns `{ user, token }` |
| `login` | Authenticates, revokes old tokens, issues new token, returns `{ user, token }` |
| `logout` | Revokes **only the current request's token** (not all sessions) |
| `me` | Returns authenticated user payload |

- Login revokes all previous tokens before issuing a new one to enforce single active
  session. Remove `$user->tokens()->delete()` if multi-device login is needed.
- Password check uses `Hash::check()` — no `Auth::attempt()` to avoid session state.
- `userPayload()` private helper whitelists which user fields are returned (never
  returns `password`, `remember_token`, etc.).
- Throws `ValidationException` on bad credentials (returns 422 with structured errors,
  not a generic 401) — consistent with Laravel's own auth conventions.

#### 7. Updated — `routes/api.php`
**File:** `routes/api.php`

Added auth route group:
```
POST  /api/auth/register  — public
POST  /api/auth/login     — public
POST  /api/auth/logout    — protected (auth:sanctum)
GET   /api/auth/me        — protected (auth:sanctum)
```

---

### Files Changed / Created

| File | Type | Change |
|------|------|--------|
| `composer.json` | Modified | Added `laravel/sanctum` dependency |
| `config/sanctum.php` | Created | Published Sanctum config |
| `database/migrations/2026_05_10_030151_create_personal_access_tokens_table.php` | Created | Sanctum tokens table |
| `database/migrations/2026_05_10_100000_add_role_to_users_table.php` | Created | `role` column on users |
| `app/Models/User.php` | Modified | `HasApiTokens`, `role` fillable, helper methods |
| `app/Http/Requests/Auth/RegisterRequest.php` | Created | Registration validation |
| `app/Http/Requests/Auth/LoginRequest.php` | Created | Login validation |
| `app/Http/Controllers/AuthController.php` | Created | register / login / logout / me |
| `routes/api.php` | Modified | Auth route group added |

### New Endpoints

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/auth/register` | Public | Create account (student or teacher) |
| POST | `/api/auth/login` | Public | Authenticate and receive token |
| POST | `/api/auth/logout` | `auth:sanctum` | Revoke current token |
| GET | `/api/auth/me` | `auth:sanctum` | Get authenticated user |

### Sample Login Response
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": {
      "id": 1,
      "name": "Ahmed Ali",
      "email": "ahmed@example.com",
      "role": "student",
      "email_verified_at": null,
      "created_at": "2026-05-10T..."
    },
    "token": "1|abc123..."
  }
}
```

### Database Changes

| Migration | Table | Change |
|-----------|-------|--------|
| `2026_05_10_030151_create_personal_access_tokens_table` | `personal_access_tokens` | New — Sanctum token storage |
| `2026_05_10_100000_add_role_to_users_table` | `users` | New column: `role` string, default `student` |

---

### Remaining Issues (Pre-Phase 3)

| # | Issue | Severity |
|---|-------|----------|
| 1 | Admin routes still fully public (no auth:sanctum guard) | CRITICAL |
| 2 | `GET /workshop-requests` returns data without auth | HIGH |
| 3 | `POST /courses` and `POST /instructors` are public | HIGH |
| 4 | No role middleware — anyone with a token can hit admin routes | HIGH |
| 5 | PayPal webhook has no signature verification | HIGH |
| 6 | Stripe webhook unsigned fallback still present | HIGH |
| 7 | No CORS configuration | HIGH |
| 8 | No rate limiting on auth endpoints | MEDIUM |
| 9 | No email verification flow | MEDIUM |
| 10 | No API Resources (raw models returned) | MEDIUM |
| 11 | No pagination on courses / instructors | MEDIUM |
| 12 | No tests | MEDIUM |

---

### Next Recommended Phase

**PHASE 3 — Roles & Protected Admin Routes**

- Create `RoleMiddleware` (or alias `role`) that checks `$user->role`
- Register middleware in `bootstrap/app.php`
- Wrap all admin routes in `auth:sanctum + role:admin`
- Wrap course/instructor creation in `auth:sanctum + role:admin,teacher`
- Move `GET /workshop-requests` behind `auth:sanctum + role:admin`
- Seed an admin user with known credentials

---

## PHASE 3 — Roles & Protected Admin Routes

**Date:** 2026-05-10
**Status:** ✅ COMPLETE

### What Was Changed

#### 1. Created — `RoleMiddleware`
**File:** `app/Http/Middleware/RoleMiddleware.php`

- Accepts one or more roles as variadic parameters: `role:admin` or `role:admin,teacher`
- Must be placed **after** `auth:sanctum` in the middleware chain so `$request->user()` is populated
- Returns `403 Forbidden` with consistent `{ success, message }` envelope if the user's role
  is not in the allowed list
- Returns `403` (not `401`) — the user is authenticated but lacks permission

#### 2. Registered — `role` middleware alias
**File:** `bootstrap/app.php`

- Registered `role` as a named alias for `RoleMiddleware` in `->withMiddleware()`
- Laravel 11-style alias registration (no Kernel.php — uses the application builder)

#### 3. Protected — Admin routes
**File:** `routes/api.php`

All admin routes now require `auth:sanctum + role:admin`:

| Endpoint | Before | After |
|----------|--------|-------|
| `GET /api/admin/dashboard` | 🔓 Public | 🔒 admin only |
| `GET /api/admin/registrations` | 🔓 Public | 🔒 admin only |
| `PATCH /api/admin/registrations/{id}/status` | 🔓 Public | 🔒 admin only |
| `GET /api/workshop-requests` | 🔓 Public | 🔒 admin only |

#### 4. Protected — Content management routes
**File:** `routes/api.php`

Course and instructor creation now require `auth:sanctum + role:admin,teacher`:

| Endpoint | Before | After |
|----------|--------|-------|
| `POST /api/courses` | 🔓 Public | 🔒 admin + teacher |
| `POST /api/instructors` | 🔓 Public | 🔒 admin + teacher |

#### 5. Updated — `DatabaseSeeder`
**File:** `database/seeders/DatabaseSeeder.php`

- Admin account seeded: `admin@emc.test` / `Admin@12345` (change in production)
- Dev test user seeded: `test@example.com` / `password` (student role)
- Uses `updateOrCreate` — safe to re-run without duplicating users
- **Important:** Change admin password immediately after deploying to production

---

### Files Changed / Created

| File | Type | Change |
|------|------|--------|
| `app/Http/Middleware/RoleMiddleware.php` | Created | Variadic role check middleware |
| `bootstrap/app.php` | Modified | `role` middleware alias registered |
| `routes/api.php` | Modified | Admin and management routes now behind auth + role middleware |
| `database/seeders/DatabaseSeeder.php` | Modified | Admin + test user seeded with roles |

### Middleware Chain (verified via `php artisan route:list -v`)

```
GET  api/admin/dashboard
     ⇂ api
     ⇂ Illuminate\Auth\Middleware\Authenticate:sanctum
     ⇂ App\Http\Middleware\RoleMiddleware:admin

POST api/courses
     ⇂ api
     ⇂ Illuminate\Auth\Middleware\Authenticate:sanctum
     ⇂ App\Http\Middleware\RoleMiddleware:admin,teacher
```

### Seeded Accounts

| Email | Password | Role | Purpose |
|-------|----------|------|---------|
| `admin@emc.test` | `Admin@12345` | admin | Platform administration |
| `test@example.com` | `password` | student | Dev/testing |

---

### Complete Route Protection Status (post Phase 3)

| Endpoint | Method | Guard |
|----------|--------|-------|
| `/api/health` | GET | Public |
| `/api/auth/register` | POST | Public |
| `/api/auth/login` | POST | Public |
| `/api/auth/logout` | POST | `auth:sanctum` |
| `/api/auth/me` | GET | `auth:sanctum` |
| `/api/courses` | GET | Public |
| `/api/courses/{slug}` | GET | Public |
| `/api/courses` | POST | `auth:sanctum + role:admin,teacher` |
| `/api/instructors` | GET | Public |
| `/api/instructors/{id}` | GET | Public |
| `/api/instructors` | POST | `auth:sanctum + role:admin,teacher` |
| `/api/register` | POST | Public |
| `/api/workshop-requests` | POST | Public |
| `/api/workshop-requests` | GET | `auth:sanctum + role:admin` |
| `/api/admin/dashboard` | GET | `auth:sanctum + role:admin` |
| `/api/admin/registrations` | GET | `auth:sanctum + role:admin` |
| `/api/admin/registrations/{id}/status` | PATCH | `auth:sanctum + role:admin` |
| `/api/payments/stripe/webhook` | POST | Public (Stripe server) |
| `/api/payments/paypal/webhook` | POST | Public (PayPal server) |
| `/api/payments/fake/confirm/{id}` | POST | Public (local env guard in controller) |
| `/api/payments/fake/fail/{id}` | POST | Public (local env guard in controller) |

---

### Remaining Issues (Pre-Phase 4)

| # | Issue | Severity |
|---|-------|----------|
| 1 | PayPal webhook has no signature verification | HIGH |
| 2 | Stripe webhook unsigned fallback still present | HIGH |
| 3 | No CORS configuration | HIGH |
| 4 | No rate limiting on auth/registration endpoints | MEDIUM |
| 5 | No API Resources — raw Eloquent models returned | MEDIUM |
| 6 | No pagination on `GET /courses`, `GET /instructors` | MEDIUM |
| 7 | No tests | MEDIUM |
| 8 | Admin password should be changed in production | MEDIUM |

---

### Next Recommended Phase

**PHASE 4 — API Resources & Pagination**

- Create `CourseResource`, `InstructorResource`, `RegistrationResource`,
  `PaymentResource`, `WorkshopRequestResource`, `UserResource`
- Add pagination to all list endpoints
- Ensure no sensitive fields are exposed
- Keep frontend compatibility where possible

---

## PHASE 4 — API Resources & Pagination

**Date:** 2026-05-10
**Status:** ✅ COMPLETE

### What Was Changed

#### 1. Created — 7 API Resource classes
**Directory:** `app/Http/Resources/`

| Resource | Purpose |
|----------|---------|
| `UserResource` | Authenticated user payload — no password/token leakage |
| `CourseFeatureResource` | Nested course bullet-point features |
| `InstructorResource` | Instructor profile; `courses` only when eager-loaded |
| `CourseResource` | Full course shape; `instructor` + `features` when eager-loaded |
| `PaymentResource` | Payment status; `checkout_url` nulled after payment confirmed; `provider_payment_id` excluded |
| `RegistrationResource` | Registration with nested `course` + `payments` when loaded |
| `WorkshopRequestResource` | Workshop request; `speaker_photo` raw path converted to full public URL |

All resources use `whenLoaded()` for nested relationships — no accidental N+1 queries
from unnecessary eager loading, and nested objects only appear when the controller
explicitly loaded the relationship.

#### 2. Updated — `CourseController`
**File:** `app/Http/Controllers/CourseController.php`

- `index`: paginated (`per_page` param, default 15, max 100), returns `CourseResource::collection()`
  with `additional(['success', 'message'])`.
- `show`: returns `(new CourseResource())->additional()->response()`.
- `store`: returns `(new CourseResource())->additional()->response()->setStatusCode(201)`.

#### 3. Updated — `InstructorController`
**File:** `app/Http/Controllers/InstructorController.php`

- `index`: paginated, returns `InstructorResource::collection()`.
- `show`: returns `InstructorResource` (with `courses` eager-loaded).
- `store`: returns `InstructorResource` with correct 200/201 status code.

#### 4. Updated — `AdminController`
**File:** `app/Http/Controllers/AdminController.php`

- `dashboard`: `recent_registrations` now wrapped in `RegistrationResource::collection()`.
- `registrations`: paginated (default 20, max 100), filterable by `course_id` and `status`,
  returns `RegistrationResource::collection()` with eager-loaded `course` + `payments`.
- `updateRegistrationStatus`: returns `RegistrationResource` after loading relationships.

#### 5. Updated — `WorkshopRequestController`
**File:** `app/Http/Controllers/WorkshopRequestController.php`

- `index`: returns `WorkshopRequestResource::collection()` instead of raw paginator.
- `store`: returns `WorkshopRequestResource` with 201 status.
- `speaker_photo` raw storage path is converted to a full public URL in the resource.

#### 6. Updated — `RegistrationController`
**File:** `app/Http/Controllers/RegistrationController.php`

- Free course response: `RegistrationResource` via `->additional()->response()->setStatusCode(201)`.
- Paid course response: `RegistrationResource` for the registration object; `checkout_url`
  and `payment_status` remain at the top level for easy frontend consumption.

#### 7. Updated — `AuthController`
**File:** `app/Http/Controllers/AuthController.php`

- Replaced manual `userPayload()` helper with `new UserResource($user)`.
- Applies to `register`, `login`, and `me` responses.

---

### API Response Shape (standardised)

**Paginated list (e.g. `GET /api/courses?per_page=10`):**
```json
{
  "data": [ { ...CourseResource... } ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "last_page": 4, "per_page": 10, "total": 37 },
  "success": true,
  "message": "Courses retrieved successfully"
}
```

**Single item (e.g. `GET /api/courses/intro-to-arabic`):**
```json
{
  "data": { ...CourseResource... },
  "success": true,
  "message": "Course retrieved successfully"
}
```

**Frontend migration note:** `GET /api/courses` previously returned a bare array `[...]`.
It now returns `{ data: [...], meta: {...}, ... }`. Frontend should read `response.data`
for the array and `response.meta.total` for count. `GET /api/courses/{slug}` previously
returned a bare object; it now returns `{ data: {...} }` — read `response.data`.

---

### Files Created

| File | Purpose |
|------|---------|
| `app/Http/Resources/UserResource.php` | User payload resource |
| `app/Http/Resources/CourseFeatureResource.php` | Feature bullet resource |
| `app/Http/Resources/InstructorResource.php` | Instructor resource |
| `app/Http/Resources/CourseResource.php` | Course resource |
| `app/Http/Resources/PaymentResource.php` | Payment resource |
| `app/Http/Resources/RegistrationResource.php` | Registration resource |
| `app/Http/Resources/WorkshopRequestResource.php` | Workshop request resource |

### Files Modified

| File | Change |
|------|--------|
| `app/Http/Controllers/CourseController.php` | Pagination + CourseResource |
| `app/Http/Controllers/InstructorController.php` | Pagination + InstructorResource |
| `app/Http/Controllers/AdminController.php` | Pagination + RegistrationResource |
| `app/Http/Controllers/WorkshopRequestController.php` | WorkshopRequestResource |
| `app/Http/Controllers/RegistrationController.php` | RegistrationResource |
| `app/Http/Controllers/AuthController.php` | UserResource replaces manual payload |

---

### Pagination Query Parameters

All paginated list endpoints support:

| Param | Default | Max | Endpoint |
|-------|---------|-----|----------|
| `per_page` | 15 | 100 | `GET /api/courses` |
| `per_page` | 15 | 100 | `GET /api/instructors` |
| `per_page` | 20 | 100 | `GET /api/admin/registrations` |
| `per_page` | 15 | 100 | `GET /api/workshop-requests` |

Filter params on `GET /api/admin/registrations`: `course_id`, `status`
Filter params on `GET /api/workshop-requests`: `status`

---

### Remaining Issues (Pre-Phase 5)

| # | Issue | Severity |
|---|-------|----------|
| 1 | PayPal webhook has no signature verification | HIGH |
| 2 | Stripe webhook unsigned fallback still present | HIGH |
| 3 | No CORS configuration (`config/cors.php` missing) | HIGH |
| 4 | No rate limiting on auth/registration endpoints | MEDIUM |
| 5 | No tests | MEDIUM |
| 6 | Admin password should be changed in production | MEDIUM |

---

### Next Recommended Phase

**PHASE 5 — CORS, Rate Limiting & Webhook Security**

- Publish and configure `cors.php` with `FRONTEND_URL`
- Add `throttle` middleware to auth and registration endpoints
- Remove Stripe webhook unsigned JSON fallback
- Document PayPal webhook verification limitation
- Add PayPal webhook verification if feasible

---

## PHASE 5 — CORS, Rate Limiting & Webhook Security

**Date:** 2026-05-10
**Status:** ✅ COMPLETE

### What Was Changed

#### 1. Created — `config/cors.php`
**File:** `config/cors.php`

- CORS paths cover `api/*` and `sanctum/csrf-cookie`.
- Allowed origins read from env: `CORS_ALLOWED_ORIGINS` (comma-separated list) with
  fallback to `FRONTEND_URL`, then `http://localhost:5173`.
- `supports_credentials` is `false` — we use Bearer tokens, not cookies.
- `max_age` configurable via `CORS_MAX_AGE` env var (default 0 for dev; set 86400 in prod).

#### 2. Configured — Named rate limiters in `AppServiceProvider`
**File:** `app/Providers/AppServiceProvider.php`

| Limiter name | Limit | Applied to |
|---|---|---|
| `api` | 60 req/min per user/IP | Global fallback |
| `auth` | 10 req/min per IP | Auth endpoints |
| `registration` | 5 req/min per IP | `POST /api/register` |
| `workshop` | 5 req/min per IP | `POST /api/workshop-requests` |

#### 3. Applied — `throttle` middleware to sensitive routes
**File:** `routes/api.php`

- Auth group (`/auth/*`): `throttle:auth`
- Course registration: `throttle:registration`
- Workshop request submission: `throttle:workshop`
- Webhook routes: **no throttle** — webhook retry logic must not be blocked

#### 4. Added — Custom 429 JSON response handler
**File:** `bootstrap/app.php`

Laravel's default `ThrottleRequestsException` handler returns plain text.
Added a custom renderer so rate-limited responses use the consistent envelope:
```json
{ "success": false, "message": "Too many requests. Please slow down and try again." }
```

#### 5. Fixed — Stripe webhook security
**File:** `app/Http/Controllers/PaymentController.php`

**Before:** If `STRIPE_WEBHOOK_SECRET` was empty, the code silently fell back to
parsing the raw JSON payload without any signature verification — accepting any
unsigned request as legitimate.

**After:**
- If `STRIPE_WEBHOOK_SECRET` is not configured, return HTTP 500 with a logged error.
  No payload is processed.
- `Stripe\Exception\SignatureVerificationException` is caught separately and returns
  HTTP 400 (Bad Request) instead of 500.
- All other parsing errors also return 400.
- Successful business logic errors (missing session ID etc.) remain 422.

#### 6. Added — PayPal webhook signature verification
**File:** `app/Http/Controllers/PaymentController.php`

- Uses `$paypal->verifyWebHook()` with the five PayPal security headers
  (`PAYPAL-AUTH-ALGO`, `PAYPAL-CERT-URL`, `PAYPAL-TRANSMISSION-ID`,
  `PAYPAL-TRANSMISSION-SIG`, `PAYPAL-TRANSMISSION-TIME`) plus `PAYPAL_WEBHOOK_ID`.
- If `PAYPAL_WEBHOOK_ID` is configured and verification returns anything other than
  `SUCCESS`, the webhook returns HTTP 400 immediately.
- If `PAYPAL_WEBHOOK_ID` is **not configured**, a `Log::warning()` is emitted and
  processing continues (backward-compatible for existing deployments). Set
  `PAYPAL_WEBHOOK_ID` in production to enforce verification.

#### 7. Added — `PAYPAL_WEBHOOK_ID` configuration
**Files:** `config/services.php`, `.env.example`

- Added `'webhook_id' => env('PAYPAL_WEBHOOK_ID')` under `services.paypal`.
- Added `PAYPAL_WEBHOOK_ID=` to `.env.example` with an empty default.

---

### Files Changed

| File | Change |
|------|--------|
| `config/cors.php` | Created — multi-origin CORS config bound to env vars |
| `app/Providers/AppServiceProvider.php` | 4 named rate limiters defined |
| `routes/api.php` | `throttle:auth`, `throttle:registration`, `throttle:workshop` applied |
| `bootstrap/app.php` | Custom 429 JSON exception renderer added |
| `app/Http/Controllers/PaymentController.php` | Stripe unsigned-fallback removed; PayPal verification added |
| `config/services.php` | `paypal.webhook_id` key added |
| `.env.example` | `PAYPAL_WEBHOOK_ID=` added |

---

### Rate Limiting Summary

| Endpoint | Limit |
|----------|-------|
| `POST /api/auth/register` | 10 req/min per IP |
| `POST /api/auth/login` | 10 req/min per IP |
| `POST /api/auth/logout` | 10 req/min per IP |
| `GET  /api/auth/me` | 10 req/min per IP |
| `POST /api/register` | 5 req/min per IP |
| `POST /api/workshop-requests` | 5 req/min per IP |
| `POST /api/payments/*/webhook` | No limit (webhook retry safety) |
| All other API routes | 60 req/min per user/IP (global `api` limiter) |

---

### Webhook Security Summary

| Provider | Verification | Failure response |
|----------|---|---|
| Stripe | `Webhook::constructEvent()` + signature header. Secret **required** — returns 500 if not set. | 400 on bad signature |
| PayPal | `verifyWebHook()` + 5 transport headers + `PAYPAL_WEBHOOK_ID`. Warning-only if ID not set. | 400 on failed verification |

---

### Environment Variables Added

```env
# CORS
CORS_ALLOWED_ORIGINS=http://localhost:5173   # comma-separated for multiple origins
CORS_MAX_AGE=0                                # set to 86400 in production

# PayPal webhook verification
PAYPAL_WEBHOOK_ID=                            # get from PayPal Developer Dashboard
```

---

### Remaining Issues (Pre-Phase 6)

| # | Issue | Severity |
|---|-------|----------|
| 1 | No user profile update endpoint | HIGH |
| 2 | No enrollment/course-access system | HIGH |
| 3 | No course session/scheduling table | HIGH |
| 4 | Admin password should be changed in production | MEDIUM |
| 5 | No tests | MEDIUM |
| 6 | Orphaned receipt files if DB transaction fails | LOW |

---

### Next Recommended Phase

**PHASE 6 — Profile System**

- `GET  /api/profile` — return authenticated user's full profile
- `PUT  /api/profile` — update name, phone, bio
- `POST /api/profile/avatar` — upload avatar image
- Store avatar in `public` disk under `avatars/`
- `avatar_url` exposed via `UserResource`

---

## PHASE 6 — Profile System

**Status:** 🔲 PENDING

---

## PHASE 7 — Enrollment System

**Status:** 🔲 PENDING

---

## PHASE 8 — Sessions & Scheduling

**Status:** 🔲 PENDING

---

## PHASE 9 — Dashboard APIs

**Status:** 🔲 PENDING

---

## PHASE 10 — Notifications & Contact Messages

**Status:** 🔲 PENDING

---

## PHASE 11 — Course Update/Delete & Admin Course Management

**Status:** 🔲 PENDING

---

## PHASE 12 — Resources / Attachments

**Status:** 🔲 PENDING
