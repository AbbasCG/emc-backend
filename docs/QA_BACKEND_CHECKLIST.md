# EMC Backend — QA Checklist

Use this document for manual QA before every production release.
Run `php artisan test` first and confirm all automated tests pass.
Then work through each section manually using a REST client (Postman / curl).

---

## Automated Gate

```bash
php artisan test --stop-on-failure
```

> All tests must be green before proceeding to manual QA.

---

## 1. Auth Flow

### 1.1 Registration

- [ ] `POST /api/auth/register` with valid data → 201, returns `token` and `user`
- [ ] `POST /api/auth/register` with existing email → 422, error on `email`
- [ ] `POST /api/auth/register` with missing `name` / `email` / `password` → 422
- [ ] `POST /api/auth/register` with mismatched passwords → 422
- [ ] Newly registered user has `role=student` by default
- [ ] Password is **not** returned in any response field

### 1.2 Login

- [ ] `POST /api/auth/login` with correct credentials → 200, returns `token`
- [ ] `POST /api/auth/login` with wrong password → 422, `success: false`
- [ ] `POST /api/auth/login` with non-existent email → 422
- [ ] Inactive user login — token may be issued but `GET /api/auth/me` must return 403

### 1.3 Me

- [ ] `GET /api/auth/me` with valid Sanctum token → 200, returns user object
- [ ] `GET /api/auth/me` without token → 401
- [ ] Response does not include `password` or `remember_token`

### 1.4 Logout

- [ ] `POST /api/auth/logout` with valid token → 200, token invalidated
- [ ] Subsequent `GET /api/auth/me` with same token → 401

### 1.5 Password Reset

- [ ] `POST /api/auth/forgot-password` with registered email → 200 (email sent)
- [ ] `POST /api/auth/forgot-password` with unknown email → 200 (no enumeration)
- [ ] Check email inbox — reset link contains a valid token
- [ ] `POST /api/auth/reset-password` with valid token → 200, login works with new password
- [ ] `POST /api/auth/reset-password` with expired/invalid token → 422
- [ ] After reset, old Sanctum tokens are invalid (revoked)

---

## 2. Student Flow

### 2.1 Dashboard

- [ ] `GET /api/student/dashboard` as student → 200 with KPIs
- [ ] Same endpoint as admin → 403
- [ ] Same endpoint unauthenticated → 401

### 2.2 Available Courses

- [ ] `GET /api/student/available-courses` → returns only `status=active` courses
- [ ] Draft and archived courses must not appear

### 2.3 Registration

- [ ] Register for a **free** course → 201, `type: free`, registration status = Registered
- [ ] Register for a **paid** course with `payment_provider=fake` → 201, `type: paid`, checkout_url returned
- [ ] Register again for the same course with the same email → 422 (duplicate blocked)
- [ ] Register for a different course with the same email → 201 (allowed)
- [ ] Guest registration → `user_id` is null (no email lookup)
- [ ] Authenticated registration → `user_id` = authenticated user's ID

### 2.4 My Registrations

- [ ] `GET /api/student/registrations` → only own records returned
- [ ] Login as a different student → their registrations not visible

### 2.5 My Courses / Learning

- [ ] `GET /api/student/courses` → enrolled courses only
- [ ] `GET /api/student/learning` → course modules and progress
- [ ] Non-enrolled course content not accessible via student endpoints

### 2.6 Certificates

- [ ] `GET /api/student/certificates` → own certificates only
- [ ] `GET /api/certificates/verify/{code}` (public) → valid code returns certificate data
- [ ] Invalid code → 404

---

## 3. Instructor Flow

### 3.1 Dashboard

- [ ] `GET /api/instructor/dashboard` as role=instructor → 200
- [ ] `GET /api/instructor/dashboard` as role=teacher (alias) → 200
- [ ] Same endpoint as student → 403

### 3.2 Course Access

- [ ] `GET /api/instructor/courses` → only courses assigned to this instructor's profile
- [ ] `GET /api/instructor/courses/{course}/content` for assigned course → 200
- [ ] Same endpoint for unassigned course → 403 or 404

### 3.3 Course Content CRUD

- [ ] `POST /api/instructor/courses/{course}/modules` → creates module in DB
- [ ] `PUT /api/instructor/courses/{course}/modules/{module}` → updates title/order
- [ ] `DELETE /api/instructor/courses/{course}/modules/{module}` → removes module
- [ ] Attempt to modify module of unassigned course → 403
- [ ] Student attempting module create → 403

### 3.4 Students

- [ ] `GET /api/instructor/students` → students enrolled in instructor's courses

---

## 4. Admin Flow

### 4.1 Dashboard

- [ ] `GET /api/admin/dashboard` as role=admin → 200
- [ ] `GET /api/admin/dashboard` as role=super_admin → 200
- [ ] Student/instructor/partner → 403

### 4.2 User Management

- [ ] `GET /api/admin/users` → paginated user list
- [ ] `POST /api/admin/users` → creates user
- [ ] `PUT /api/admin/users/{user}` → updates role/is_active
- [ ] Set `is_active=false` → user immediately blocked on next request
- [ ] `DELETE /api/admin/users/{user}` as admin → 403 (super_admin only)
- [ ] `DELETE /api/admin/users/{user}` as super_admin → 200

### 4.3 Course Management

- [ ] `GET /api/admin/courses` → all courses paginated
- [ ] `POST /api/admin/courses` → creates course in `draft` status
- [ ] `PUT /api/admin/courses/{course}/assign-instructor` with instructor profile ID → assigns correctly
- [ ] `PUT /api/admin/courses/{course}/assign-instructor` with user ID (not profile ID) → should be validated or rejected

### 4.4 Registrations

- [ ] `GET /api/admin/registrations` → all registrations
- [ ] `GET /api/admin/registrations/{id}` → single registration detail
- [ ] `PUT /api/admin/registrations/{id}/status` → updates status

### 4.5 Payments

- [ ] `GET /api/admin/payments` → all payments
- [ ] Stripe webhook: valid Stripe signature → 200, payment updated
- [ ] Stripe webhook: invalid signature → 400

### 4.6 Roles & Permissions

- [ ] Finance user → cannot access `/api/admin/registrations` (403)
- [ ] Finance user → cannot access `/api/admin/users` (403)
- [ ] Partner → cannot access any `/api/admin/*` route (403)

---

## 5. Payment Flow

### 5.1 Fake Payment (Testing)

- [ ] `POST /api/payments/fake/confirm/{id}` → payment status = Confirmed, registration = Registered
- [ ] `POST /api/payments/fake/fail/{id}` → payment status = Failed, registration stays pending
- [ ] Fake provider rejected when `ALLOW_FAKE_PAYMENTS=false` in production

### 5.2 Stripe

- [ ] Stripe checkout URL returned for paid courses with `payment_provider=stripe`
- [ ] Webhook `POST /api/payments/stripe/webhook` with valid payload + signature → 200
- [ ] Webhook with invalid signature → 400

### 5.3 PayPal

- [ ] PayPal checkout URL returned for `payment_provider=paypal`
- [ ] Webhook `POST /api/payments/paypal/webhook` → 200 on valid event

---

## 6. LMS Flow

### 6.1 Modules

- [ ] Admin/instructor creates module → appears in course content
- [ ] Module has `sort_order` — drag reorder works via `POST /courses/{id}/modules/reorder`

### 6.2 Materials

- [ ] Admin uploads material to course → file stored in private disk
- [ ] SVG file upload → **rejected** (MIME hardening)
- [ ] Enrolled student downloads material via `GET /api/materials/{id}/download` → file served
- [ ] Non-enrolled student downloads → 403
- [ ] Material with `is_visible=false` → not downloadable by student

### 6.3 Assignments

- [ ] Student submits assignment → submission created
- [ ] Instructor reviews submission → score/feedback saved

### 6.4 Quizzes

- [ ] Student submits quiz → quiz attempt created with score
- [ ] Re-attempt (if allowed) → new attempt created

---

## 7. Notification Flow

### 7.1 List & Count

- [ ] `GET /api/notifications` → own notifications only
- [ ] `GET /api/notifications/unread-count` → correct unread count (integer)
- [ ] Other user's notifications never appear

### 7.2 Mark Read

- [ ] `PUT /api/notifications/{id}/read` → `is_read=true`, `read_at` set
- [ ] `PUT /api/notifications/read-all` → all notifications marked read
- [ ] After mark-all-read, unread count = 0
- [ ] Attempt to mark another user's notification as read → 403/404

### 7.3 Delete

- [ ] `DELETE /api/notifications/{id}` → record removed from DB
- [ ] Attempt to delete another user's notification → 403/404

### 7.4 Content

- [ ] Notification `action_url` is preserved (not null) when set
- [ ] Notification `type` is one of: info, success, warning, error

---

## 8. Security Checks

- [ ] `GET /api/health` → 200, all checks `"status": "ok"` (or `"warn"` with zero `failed_jobs`)
- [ ] `php artisan emc:production-check` → exits with code 0
- [ ] `GET /api/this-does-not-exist` → 404 JSON with `success: false`
- [ ] Inactive user accessing any protected route → 403, suspension message
- [ ] Brute-force: 11+ failed login attempts → 429 (throttle:auth)
- [ ] SQL injection attempt in search → sanitized, no 500
- [ ] Password not visible in any API response
- [ ] Admin route without token → 401 (not 302 redirect)

---

## 9. Health & Monitoring

- [ ] `GET /api/health` returns all checks as `ok`
- [ ] `GET /up` (Laravel built-in ping) → 200
- [ ] Health response includes: `app_version`, `php_version`, `laravel_version`, `memory_mb`
- [ ] Queue `failed_jobs` = 0 after test run
- [ ] `php artisan schedule:list` shows all 5 scheduled tasks

---

## 10. Pre-Release Final Sign-Off

```bash
# 1. Run full test suite
php artisan test

# 2. Production readiness gate
php artisan emc:production-check

# 3. Health check
curl -s https://api.emc.sa/api/health | python3 -m json.tool

# 4. Test login
curl -X POST https://api.emc.sa/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@emc.sa","password":"your-password"}'

# 5. Confirm no failed jobs
php artisan queue:monitor

# 6. Verify scheduler
php artisan schedule:list
```

| Check | Result |
|-------|--------|
| `php artisan test` passes | ☐ |
| `emc:production-check` exits 0 | ☐ |
| `/api/health` → `status: ok` | ☐ |
| Login works | ☐ |
| No failed jobs | ☐ |
| Scheduler configured | ☐ |
| Backup runs cleanly | ☐ |
| Sentry receives test event | ☐ |

---

*Last updated: 2026-05-31*
