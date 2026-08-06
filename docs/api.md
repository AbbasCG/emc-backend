# EMC Backend — API Reference

> **Base URL:** `https://api.emc.sa/api`  
> **Auth:** Bearer token via Sanctum — include in `Authorization: Bearer <token>` header.  
> **Content-Type:** `application/json`

---

## Response Shape

All responses follow one of these shapes.

**Success (single resource)**
```json
{
  "success": true,
  "message": "...",
  "data": { ... }
}
```

**Success (list)**
```json
{
  "success": true,
  "data": [ ... ],
  "meta": { "current_page": 1, "last_page": 4, "per_page": 15, "total": 60 }
}
```

**Error**
```json
{
  "success": false,
  "message": "...",
  "errors": { "field": ["validation message"] }
}
```

---

## Authentication

### Register
`POST /auth/register`

```json
{
  "name": "Ahmed Ali",
  "email": "ahmed@example.com",
  "password": "Secret123!",
  "password_confirmation": "Secret123!"
}
```

Response `201`:
```json
{
  "success": true,
  "message": "Registration successful",
  "data": { "user": { "id": 1, "name": "Ahmed Ali", "role": "student" }, "token": "..." }
}
```

### Login
`POST /auth/login`

```json
{ "email": "ahmed@example.com", "password": "Secret123!" }
```

### Logout
`POST /auth/logout`  *(requires auth)*

### Get current user
`GET /auth/me`  *(requires auth)*

---

## Public Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET`  | `/courses` | List published courses (paginated) |
| `GET`  | `/courses/{slug}` | Get single course detail |
| `GET`  | `/tracks` | List all tracks |
| `POST` | `/register` | Submit course registration |
| `GET`  | `/certificates/verify/{code}` | Verify a certificate by its verification code |
| `POST` | `/workshop-requests` | Submit a workshop request form |
| `GET`  | `/knowledge` | Browse public knowledge articles |
| `GET`  | `/search?q=keyword` | Global search (results vary by role) |

### Course Registration
`POST /register`

```json
{
  "course_id": 5,
  "full_name": "Sara Khalid",
  "phone": "0512345678",
  "email": "sara@example.com",
  "city": "Riyadh",
  "gender": "female",
  "payment_provider": "fake"
}
```

`payment_provider` accepted values: `fake`, `stripe`, `paypal` (or omit for free courses).

---

## Payment Flow

### Fake Payment (development / testing)

1. `POST /register` with `"payment_provider": "fake"` → returns `checkout_url`
2. `POST /payments/fake/confirm/{payment_id}` → confirms payment
3. `POST /payments/fake/fail/{payment_id}` → marks as failed

### Stripe Checkout

1. `POST /register` with `"payment_provider": "stripe"` → returns `checkout_url`
2. Customer completes Stripe checkout
3. Stripe sends webhook to `POST /webhooks/stripe`
4. Registration is automatically confirmed

### PayPal

1. `POST /register` with `"payment_provider": "paypal"` → returns `checkout_url`
2. Customer completes PayPal checkout
3. PayPal sends webhook to `POST /webhooks/paypal`

---

## Student Endpoints *(requires auth, role: student)*

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET`  | `/student/dashboard` | Student KPIs and overview |
| `GET`  | `/student/registrations` | Own registrations |
| `GET`  | `/student/certificates` | Own certificates |
| `GET`  | `/student/certificates/{id}` | Single certificate |
| `GET`  | `/student/progress` | Learning progress |
| `GET`  | `/student/sessions` | Upcoming sessions |
| `GET`  | `/student/assignments` | Assigned tasks |
| `GET`  | `/student/courses/{courseId}/modules` | Course modules |
| `GET`  | `/student/lessons/{lesson}` | Lesson detail |
| `POST` | `/student/quizzes/{quiz}/submit` | Submit quiz answers |

---

## Instructor Endpoints *(requires auth, role: teacher)*

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET`  | `/instructor/dashboard` | Instructor overview |
| `GET`  | `/instructor/courses` | Assigned courses |
| `GET`  | `/instructor/sessions` | Sessions to manage |
| `GET`  | `/instructor/students` | Students in courses |
| `GET`  | `/instructor/assignments` | Course assignments |
| `POST` | `/instructor/assignments` | Create assignment |
| `GET`  | `/instructor/submissions` | View submissions |
| `PUT`  | `/instructor/submissions/{id}/grade` | Grade a submission |

---

## Partner Endpoints *(requires auth, role: partner)*

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET`  | `/partner/dashboard` | Partner overview |
| `GET`  | `/partner/programs` | Partner programs |
| `GET`  | `/partner/reports` | Partnership reports |
| `GET`  | `/partner/documents` | Shared documents |
| `POST` | `/partner/documents` | Upload a document |

---

## Admin Endpoints *(requires auth, role: admin)*

### Users
| `GET`    | `/admin/users`         | List users |
| `POST`   | `/admin/users`         | Create user |
| `PUT`    | `/admin/users/{id}`    | Update user |
| `DELETE` | `/admin/users/{id}`    | Delete user |

### Courses & Tracks
| `GET/POST`        | `/admin/courses`            | List / create |
| `GET/PUT/DELETE`  | `/admin/courses/{id}`       | Read / update / delete |
| `GET/POST`        | `/admin/tracks`             | List / create |
| `GET/POST`        | `/admin/modules`            | Course modules |
| `GET/POST`        | `/admin/lessons`            | Lessons |
| `GET/POST`        | `/admin/quizzes`            | Quizzes |

### Registrations & Payments
| `GET`  | `/admin/registrations`           | List all registrations |
| `PUT`  | `/admin/registrations/{id}`      | Update registration |
| `GET`  | `/admin/payments`                | List all payments |
| `POST` | `/admin/payments/{id}/refund`    | Issue refund |

### Certificates
| `GET`  | `/admin/certificates`        | List all |
| `POST` | `/admin/certificates`        | Issue certificate |
| `POST` | `/admin/certificates/{id}/approve` | Approve |
| `POST` | `/admin/certificates/{id}/revoke`  | Revoke |

### Operations
| `GET/POST`       | `/admin/tasks`                   | Tasks CRUD |
| `GET/PUT/DELETE` | `/admin/tasks/{id}`              | Single task |
| `GET/POST`       | `/admin/meetings`                | Meetings CRUD |
| `GET/POST`       | `/admin/departments`             | Departments |

### Knowledge Base
| `GET/POST`       | `/admin/knowledge/categories` | CRUD |
| `GET/POST`       | `/admin/knowledge/articles`   | CRUD |

### Reports
| `GET/POST`       | `/admin/reports`              | Generate / list reports |
| `GET`            | `/admin/audit-logs`           | Activity log (filterable) |

### Audit Logs
`GET /admin/audit-logs?action=payment_confirmed&entity_type=Payment&from=2026-01-01&to=2026-12-31`

---

## Environment Variables

| Variable | Description |
|----------|-------------|
| `APP_URL` | Backend base URL |
| `FRONTEND_URL` | React app URL (used for email links) |
| `CORS_ALLOWED_ORIGINS` | Comma-separated frontend origins |
| `STRIPE_KEY` | Stripe publishable key |
| `STRIPE_SECRET` | Stripe secret key |
| `STRIPE_WEBHOOK_SECRET` | Stripe webhook signing secret |
| `PAYPAL_CLIENT_ID` | PayPal client ID |
| `PAYPAL_CLIENT_SECRET` | PayPal client secret |
| `MAIL_MAILER` | Mail driver (smtp, log, etc.) |
| `QUEUE_CONNECTION` | `database` for MVP |

---

## Error Codes Summary

| HTTP | Meaning |
|------|---------|
| 200  | OK |
| 201  | Created |
| 401  | Unauthenticated — missing/invalid token |
| 403  | Forbidden — role or policy check failed |
| 404  | Resource not found |
| 405  | Method not allowed |
| 422  | Validation error — see `errors` object |
| 429  | Rate limit exceeded |
| 500  | Server error |
