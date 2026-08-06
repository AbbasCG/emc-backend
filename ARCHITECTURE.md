# EMC Backend - Complete Architecture Analysis

## 1. PROJECT OVERVIEW

**Project Name:** EMC (Educational Management Course) Backend  
**Framework:** Laravel 11  
**Type:** Course Registration & Payment Management System  
**Primary Purpose:** Manage educational courses with registration, payment processing, and instructor management

**Key External Integrations:**
- Payment Gateway: Stripe, PayPal, Fake (local development)
- Mail Service: Log, SMTP, SES (configurable)
- File Storage: Local filesystem, S3 (optional)

---

## 2. DATABASE SCHEMA

### Tables & Structure

#### **users**
| Field | Type | Notes |
|-------|------|-------|
| id | bigint PK | |
| name | string | |
| email | string | unique |
| email_verified_at | timestamp | nullable |
| password | string | hashed |
| remember_token | string | nullable |
| created_at | timestamp | |
| updated_at | timestamp | |

#### **instructors**
| Field | Type | Notes |
|-------|------|-------|
| id | bigint PK | |
| name | string | required |
| title | string | nullable |
| bio | text | nullable |
| image | string | nullable (path/URL) |
| created_at | timestamp | |
| updated_at | timestamp | |

#### **courses**
| Field | Type | Notes |
|-------|------|-------|
| id | bigint PK | |
| instructor_id | foreignId | nullable, cascade delete |
| title | string | required |
| slug | string | unique, auto-generated from title |
| description | text | nullable |
| short_description | text | nullable |
| type | enum | 'free' or 'paid' (default: free) |
| price | decimal(10,2) | default: 0 |
| location | string | nullable |
| is_online | boolean | default: false |
| start_date | datetime | nullable |
| end_date | datetime | nullable |
| capacity | integer | nullable |
| status | string | default: 'active' |
| duration | string | nullable (e.g., "6 weeks") |
| training_hours | integer | nullable |
| target_audience | string | nullable |
| language | string | nullable |
| level | string | nullable (e.g., "Beginner") |
| study_days | string | nullable |
| study_time | string | nullable |
| certificate | string | nullable |
| learning_outcomes | json | nullable, stored as array |
| course_image | string | nullable (path/URL) |
| created_at | timestamp | |
| updated_at | timestamp | |

#### **course_features**
| Field | Type | Notes |
|-------|------|-------|
| id | bigint PK | |
| course_id | foreignId | required, cascade delete |
| title | string | feature description |
| sort_order | integer | default: 0 |
| created_at | timestamp | |
| updated_at | timestamp | |

#### **registrations**
| Field | Type | Notes |
|-------|------|-------|
| id | bigint PK | |
| course_id | foreignId | required, cascade delete |
| full_name | string | required |
| phone | string | required |
| email | string | required |
| city | string | nullable |
| gender | string | nullable |
| notes | text | nullable |
| status | string | default: 'Registered' |
| created_at | timestamp | |
| updated_at | timestamp | |

**Status Values:**
- "Registered" (free courses, or after payment)
- "Pending Payment" (paid courses awaiting payment)
- "Payment Confirmed" (manual admin status)
- "Cancelled", "Attended", "No Show" (admin statuses)

#### **payments**
| Field | Type | Notes |
|-------|------|-------|
| id | bigint PK | |
| registration_id | foreignId | required, cascade delete |
| amount | decimal(10,2) | default: 0 |
| provider | string | nullable ('stripe', 'paypal', 'fake') |
| provider_payment_id | string | nullable (session_id for Stripe, order_id for PayPal) |
| checkout_url | text | nullable (URL to redirect user) |
| payment_method | string | nullable |
| receipt_url | string | nullable |
| status | string | default: 'Pending Payment' |
| confirmed_at | timestamp | nullable |
| created_at | timestamp | |
| updated_at | timestamp | |

**Status Values:**
- "Pending Payment" (awaiting user to complete checkout)
- "Payment Confirmed" (payment completed)
- "Payment Failed" (payment failed or user cancelled)

### Model Relationships

```
User
├── (no direct relationships - standalone)

Instructor (1) ──── hasMany ──── (N) Course

Course (1) 
├── belongsTo ──── Instructor (nullable)
├── hasMany ──── CourseFeature (cascade delete)
└── hasMany ──── Registration (cascade delete)

CourseFeature (N) ──── belongsTo ──── (1) Course

Registration (N)
├── belongsTo ──---- (1) Course
└── hasMany ──---- (N) Payment

Payment (N) ──── belongsTo ──---- (1) Registration
```

---

## 3. API ENDPOINTS

### COURSE ENDPOINTS

| Method | Endpoint | Controller Method | Input Validation | Response |
|--------|----------|------------------|------------------|----------|
| GET | `/api/courses` | CourseController@index | - | Array of courses with instructor & features |
| GET | `/api/courses/{slug}` | CourseController@show | slug (required) | Single course object |
| POST | `/api/courses` | CourseController@store | (see below) | Created course object, 201 |

**POST /api/courses - Validation Rules:**
```
instructor_id (nullable|exists:instructors,id)
title (required|string|max:255)
description (nullable|string)
short_description (nullable|string)
instructor_name (nullable|string|max:255)
type (required|in:free,paid)
price (nullable|numeric)
location (nullable|string|max:255)
is_online (boolean)
start_date (nullable|date)
end_date (nullable|date)
capacity (nullable|integer)
status (nullable|string)
duration (nullable|string|max:255)
training_hours (nullable|integer)
target_audience (nullable|string|max:255)
language (nullable|string|max:255)
level (nullable|string|max:255)
study_days (nullable|string|max:255)
study_time (nullable|string|max:255)
certificate (nullable|string|max:255)
course_image (nullable|string|max:255)
features (nullable|array)
features.* (string|max:255)
```

---

### REGISTRATION ENDPOINTS

| Method | Endpoint | Controller Method | Input Validation | Response |
|--------|----------|------------------|------------------|----------|
| POST | `/api/register` | RegistrationController@store | (see below) | Registration object with payment data (if paid) |

**POST /api/register - Validation Rules:**
```
course_id (required|exists:courses,id)
full_name (required|string|max:255)
phone (required|string|max:30)
email (required|email|max:255)
city (nullable|string|max:255)
gender (nullable|string|max:50)
notes (nullable|string)
receipt (nullable|file|mimes:jpg,jpeg,png,pdf|max:4096)
payment_provider (nullable|in:stripe,paypal,fake)
```

**POST /api/register - Response (Free Course - 201):**
```json
{
  "message": "Registration created successfully",
  "data": {
    "id": 1,
    "course_id": 1,
    "full_name": "John Doe",
    "phone": "+1234567890",
    "email": "john@example.com",
    "city": "Cairo",
    "gender": "M",
    "notes": null,
    "status": "Registered",
    "course": {...},
    "created_at": "2026-05-04T...",
    "updated_at": "2026-05-04T..."
  }
}
```

**POST /api/register - Response (Paid Course - 201):**
```json
{
  "message": "Registration created. Complete payment to confirm registration.",
  "data": {
    "registration": {
      "id": 1,
      "course_id": 1,
      "status": "Pending Payment",
      ...
    },
    "payment_status": "Pending Payment",
    "checkout_url": "https://checkout.stripe.com/pay/cs_..."
  }
}
```

**POST /api/register - Error Response (No Payment Provider for Paid Course - 422):**
```json
{
  "message": "payment_provider is required for paid courses."
}
```

---

### PAYMENT ENDPOINTS

| Method | Endpoint | Controller Method | Description |
|--------|----------|------------------|-------------|
| POST | `/api/payments/fake/confirm/{payment}` | PaymentController@confirmFakePayment | Manually confirm fake payment (local only) |
| POST | `/api/payments/fake/fail/{payment}` | PaymentController@failFakePayment | Manually fail fake payment (local only) |
| POST | `/api/payments/stripe/webhook` | PaymentController@stripeWebhook | Stripe webhook handler |
| POST | `/api/payments/paypal/webhook` | PaymentController@paypalWebhook | PayPal webhook handler |

**POST /api/payments/fake/confirm/{payment} - Response (200):**
```json
{
  "message": "Fake payment confirmed successfully.",
  "data": {
    "payment_id": 1,
    "status": "Payment Confirmed"
  }
}
```

---

### INSTRUCTOR ENDPOINTS

| Method | Endpoint | Controller Method | Input Validation | Response |
|--------|----------|------------------|------------------|----------|
| GET | `/api/instructors` | InstructorController@index | - | Array of instructors |
| GET | `/api/instructors/{id}` | InstructorController@show | id (required) | Single instructor with courses |
| POST | `/api/instructors` | InstructorController@store | (see below) | Instructor object, 201 |

**POST /api/instructors - Validation Rules:**
```
name (required|string|max:255)
title (nullable|string|max:255)
bio (nullable|string)
image (nullable|string|max:255)
```

**Logic:** Uses `firstOrCreate` - returns existing instructor if name matches

---

### ADMIN ENDPOINTS

| Method | Endpoint | Controller Method | Response |
|--------|----------|------------------|----------|
| GET | `/api/admin/dashboard` | AdminController@dashboard | Dashboard statistics |
| GET | `/api/admin/registrations` | AdminController@registrations | Filtered registrations list |
| PATCH | `/api/admin/registrations/{id}/status` | AdminController@updateRegistrationStatus | Updated registration |

**GET /api/admin/dashboard - Response:**
```json
{
  "total_courses": 15,
  "total_registrations": 125,
  "pending_payments": 23,
  "confirmed_payments": 98,
  "recent_registrations": [...]
}
```

**GET /api/admin/registrations - Query Parameters:**
- `?course_id=1` - Filter by course
- `?status=Registered` - Filter by registration status

**PATCH /api/admin/registrations/{id}/status - Validation:**
```
status (required|in:Registered,Pending Payment,Payment Confirmed,Cancelled,Attended,No Show)
```

---

### UTILITY ENDPOINTS

| Method | Endpoint | Response |
|--------|----------|----------|
| GET | `/api/test` | `{"message": "API works"}` |

---

## 4. COURSE REGISTRATION & PAYMENT WORKFLOW

### FLOW DIAGRAM - Free Course

```
POST /api/register (free course)
    ↓
Validate input (course exists, type=free)
    ↓
DB Transaction Start
    ├─ Create Registration (status: "Registered")
    └─ DB Transaction End
    ↓
Load Registration with relationships (course, instructor, features)
    ↓
Send Email (CourseRegistrationConfirmation)
    ↓
Return 201 with registration data
```

### FLOW DIAGRAM - Paid Course

```
POST /api/register (paid course, payment_provider=stripe/paypal)
    ↓
Validate input
├─ course exists
├─ payment_provider is required
└─ fake provider only in local environment
    ↓
DB Transaction Start
    ├─ Create Registration (status: "Pending Payment")
    ├─ Based on provider:
    │  ├─ FAKE: Create local checkout URL
    │  ├─ STRIPE: Call PaymentGatewayService.createStripePayment()
    │  └─ PAYPAL: Call PaymentGatewayService.createPaypalPayment()
    ├─ Create Payment record with:
    │  ├─ amount (course.price)
    │  ├─ provider
    │  ├─ provider_payment_id
    │  ├─ checkout_url
    │  └─ status: "Pending Payment"
    └─ DB Transaction End
    ↓
Return 201 with checkout_url to frontend
```

### PAYMENT CONFIRMATION FLOW

```
WEBHOOK RECEIVED (Stripe / PayPal / Fake)
    ↓
Parse webhook payload for provider_payment_id
    ↓
DB Transaction Start (Lock for Update)
    ├─ Find Payment by provider + provider_payment_id
    ├─ Update Payment:
    │  ├─ status: "Payment Confirmed"
    │  └─ confirmed_at: now()
    ├─ Update Registration:
    │  └─ status: "Registered"
    ├─ Load Registration with relationships
    └─ DB Transaction End
    ↓
Send Email (CourseRegistrationConfirmation)
    ↓
Return 200 success
```

### ADMIN STATUS UPDATE FLOW

```
PATCH /api/admin/registrations/{id}/status
    ↓
Validate status value
    ↓
Update Registration.status
    ↓
If status = "Payment Confirmed":
    └─ Also update associated Payments to "Payment Confirmed" + set confirmed_at
    ↓
Return 200 with updated registration
```

---

## 5. EMAIL SYSTEM

### Mail Classes

**CourseRegistrationConfirmation** (app/Mail/CourseRegistrationConfirmation.php)

| Property | Value |
|----------|-------|
| Mailable | Yes (extends Mailable) |
| Queue Support | Yes (uses Queueable trait) |
| Subject | "تأكيد تسجيلك في الدورة" (Arabic: "Confirm your course registration") |
| Template | `emails.course-registration-confirmation` (markdown) |
| Serialization | Uses SerializesModels trait |

### Email Trigger Points

| Scenario | Trigger | Template Data |
|----------|---------|---------------|
| Free course registration completed | Immediately after Registration.create | Registration object with course, instructor, features |
| Paid course payment confirmed | After Payment.confirmed_at is set | Registration object with course, instructor, features |

### Email Template Location
- `resources/views/emails/course-registration-confirmation.blade.php`
- Language: Arabic (RTL - right-to-left)
- Format: HTML/Markdown
- Displays: ✓ Success badge, Registration data, Course details, Course type (paid/free)

### Mail Configuration (config/mail.php)

| Setting | Value | Notes |
|---------|-------|-------|
| Default Mailer | env('MAIL_MAILER', 'log') | Defaults to log (development) |
| Available Transports | smtp, log, ses, postmark, resend, sendmail | Configurable via .env |
| SMTP Host/Port | configurable via .env | Default: 127.0.0.1:2525 |
| Local Domain | Parsed from APP_URL | For EHLO handshake |

### Email Sending Code Pattern

```php
try {
    Mail::to($registration->email)
        ->send(new CourseRegistrationConfirmation($registration));
} catch (Throwable $exception) {
    report($exception);  // Log but don't fail registration
}
```

---

## 6. FILE HANDLING

### File Upload Points

| Endpoint | Field | Validation | Current Handling |
|----------|-------|-----------|------------------|
| POST /api/register | receipt | nullable, file, mimes: jpg/jpeg/png/pdf, max: 4096KB | ❌ **Accepted but NOT stored** |

### File Storage Configuration (config/filesystems.php)

| Disk | Driver | Root Path | URL | Visibility | Purpose |
|------|--------|-----------|-----|------------|---------|
| `local` | local | storage/app/private | (not publicly accessible) | private | Private uploads |
| `public` | local | storage/app/public | /storage | public | Public uploads |
| `s3` | s3 | (AWS S3) | configurable | configurable | Cloud storage (optional) |

### Image Handling - Courses

| Field | Type | Storage | Access |
|-------|------|---------|--------|
| `course_image` | string | Stored as path/URL string | Not managed by Laravel (external reference) |
| `instructor.image` | string | Stored as path/URL string | Not managed by Laravel (external reference) |

### Image Handling - Instructors

| Field | Type | Storage | Access |
|-------|------|---------|--------|
| `image` | string | Stored as path/URL string | Not managed by Laravel (external reference) |

### Current File Storage Pattern

Files are **NOT** automatically stored. Instead:
- Course/Instructor images are expected to be URLs or paths (managed externally)
- Receipt file is validated but not saved
- No file upload directory structure created

### Storage Links

Configured in config/filesystems.php:
```
public/storage → storage/app/public
```

Access via: `https://domain.com/storage/filename`

---

## 7. PAYMENT GATEWAY INTEGRATION

### PaymentGatewayService (app/Services/PaymentGatewayService.php)

**Method:** `createCoursePayment(Registration $registration, Course $course, string $provider): array`

#### STRIPE Implementation

```php
Call Stripe API:
├─ Mode: 'payment' (one-time payment)
├─ Line Items:
│  ├─ quantity: 1
│  ├─ price_data:
│  │  ├─ currency: 'EUR'
│  │  ├─ unit_amount: (price * 100) in cents
│  │  └─ product_data.name: course.title
├─ Metadata:
│  ├─ registration_id
│  └─ course_id
├─ Redirect URLs:
│  ├─ success_url: config('app.frontend_payment_success_url')
│  └─ cancel_url: config('app.frontend_payment_cancel_url')
└─ Returns: [provider: 'stripe', provider_payment_id: session.id, checkout_url: session.url]
```

**Config Keys:**
- `services.stripe.secret` - API secret key
- `app.frontend_payment_success_url` - Redirect after success
- `app.frontend_payment_cancel_url` - Redirect after cancel

#### PAYPAL Implementation

```php
Call PayPal API:
├─ Intent: 'CAPTURE' (immediate capture)
├─ Purchase Units:
│  ├─ reference_id: 'registration_{id}'
│  ├─ custom_id: registration.id
│  ├─ description: course.title
│  └─ amount:
│     ├─ currency_code: 'EUR'
│     └─ value: formatted price
├─ Application Context:
│  ├─ return_url: frontend_payment_success_url
│  ├─ cancel_url: frontend_payment_cancel_url
│  └─ user_action: 'PAY_NOW'
├─ Extract approval link from response
└─ Returns: [provider: 'paypal', provider_payment_id: order.id, checkout_url: approve_url]
```

**Config Keys:**
- `services.paypal` - Array with API credentials (setApiCredentials param)
- `app.frontend_payment_success_url` - Redirect after success
- `app.frontend_payment_cancel_url` - Redirect after cancel

#### FAKE Provider (Local Only)

```php
Generates local checkout URL:
├─ Format: {frontend_url}/fake-payment/{payment_id}
├─ Environment Check: Only in 'local' env
└─ Manual Endpoints:
   ├─ POST /api/payments/fake/confirm/{payment} → Success
   └─ POST /api/payments/fake/fail/{payment} → Failure
```

### Webhook Handlers

#### Stripe Webhook (`POST /api/payments/stripe/webhook`)

```
Header: Stripe-Signature
Events Listened:
├─ checkout.session.completed → confirmPayment('stripe', session_id)
├─ checkout.session.expired → failPayment('stripe', session_id)
└─ payment_intent.payment_failed → failPayment('stripe', session_id)
```

#### PayPal Webhook (`POST /api/payments/paypal/webhook`)

```
Body Fields: event_type, resource
Events Listened:
├─ CHECKOUT.ORDER.APPROVED → Capture order, then confirmPayment
├─ PAYMENT.CAPTURE.COMPLETED → confirmPayment('paypal', order_id)
├─ CHECKOUT.ORDER.VOIDED → failPayment
├─ PAYMENT.CAPTURE.DENIED → failPayment
└─ PAYMENT.CAPTURE.DECLINED → failPayment
```

---

## 8. MISSING PIECES / GAPS

### ⚠️ Critical Gaps

1. **No Workshop/Program Model**
   - Only courses exist, no separate "workshops" or "programs"
   - Task mentions "workshop submission" and "program creation" - not found in codebase

2. **File Upload NOT Implemented**
   - `receipt` field in POST /register accepts file but doesn't save it
   - No file upload directory created
   - No storage disk specified
   - No file retrieval endpoint

3. **No Authentication/Authorization**
   - No `auth` middleware on any endpoints
   - Admin endpoints are public (no role checking)
   - Anyone can create courses/instructors
   - No user authentication system implemented

4. **No Form Request Classes**
   - Validation rules inline in controllers
   - No dedicated FormRequest classes

5. **No Jobs/Queue Processing**
   - Mail is sent synchronously
   - No queued jobs for async processing
   - Queueable trait is on Mail class but not actively used

6. **No Image Upload Handling**
   - `course_image` and `instructor.image` are string fields
   - No actual image upload endpoints
   - Images must be uploaded externally

7. **No Slug Generation Validation**
   - Slug uniqueness constraint enforced at DB level
   - But no handling for slug conflicts
   - POST /courses always generates new slug (no update endpoint)

8. **No Course Update/Delete Endpoints**
   - Can only create courses
   - Cannot update or delete

9. **No Course Capacity Validation**
   - `capacity` field exists but never checked
   - Can register unlimited students even if capacity reached

10. **No Pagination/Filtering**
    - GET /courses returns all courses (no pagination)
    - GET /admin/registrations has basic filtering but no pagination

11. **No Soft Deletes**
    - No soft delete logic
    - Deletes are permanent

12. **No Audit Logging**
    - No tracking of who created/modified records
    - No created_by or updated_by fields

13. **No Rate Limiting**
    - Anyone can spam endpoints

14. **Web Routes Empty**
    - Only GET / → welcome blade view
    - All functionality is API-only

### ⚠️ Data Structure Issues

1. **Dual Instructor Storage**
   - Course has `instructor_id` (relationship) 
   - Course also has `instructor_name`, `instructor_title`, `instructor_bio`, `instructor_image` (denormalized)
   - Inconsistent: should use relationship or denormalized fields, not both

2. **Missing Email Confirmation**
   - No email verification flow for user registrations

3. **Receipt File Not Stored**
   - Validated but ignored

### ⚠️ Configuration Issues

1. **Currency Hardcoded to EUR**
   - Stripe and PayPal both hardcoded to 'EUR'
   - No multi-currency support

2. **Payment Status Values Inconsistent**
   - "Pending Payment", "Payment Confirmed", "Payment Failed"
   - But admin can set "Registered", "Cancelled", "Attended", "No Show"
   - No clear status transition rules

3. **Frontend URLs Must Be Configured**
   - Missing in default .env:
     - `FRONTEND_URL`
     - `FRONTEND_PAYMENT_SUCCESS_URL`
     - `FRONTEND_PAYMENT_CANCEL_URL`

---

## SUMMARY TABLE

| Component | Implemented | Status |
|-----------|-------------|--------|
| Course CRUD | Partial (Create only) | ⚠️ No update/delete |
| Registration | Full | ✅ Complete |
| Payment (Stripe/PayPal) | Full | ✅ Complete |
| Fake Payment (local dev) | Full | ✅ Complete |
| Instructor CRUD | Partial (Create/Read only) | ⚠️ No update/delete |
| Admin Dashboard | Basic | ⚠️ No auth |
| Email Notifications | Full | ✅ Complete |
| File Upload | Partial (validated, not stored) | ❌ Not implemented |
| Authentication | None | ❌ Not implemented |
| Authorization | None | ❌ Not implemented |
| Queue Jobs | Trait only | ❌ Not used |
| Pagination | None | ❌ Missing |
| Validation | Inline | ⚠️ No FormRequest classes |
| Soft Deletes | None | ❌ Missing |
