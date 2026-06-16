# EMC Platform — Security Review

Last updated: 2026-06-16

This document tracks the findings from the full-repository security audit and the
status of each fix. See conversation history / PR descriptions for the original
audit methodology (backend + frontend, full repo scan, not diff-only).

## Fixed findings

### HIGH — Unrestricted file upload to public disk (`DocumentController`)
- **Files changed:** `app/Http/Controllers/DocumentController.php`, `app/Http/Resources/DocumentResource.php`, `routes/api.php`, `config/filesystems.php`
- **Issue:** `store()` accepted any file type and saved it to the `public` disk, reachable by any authenticated user.
- **Fix:** Uploads now go through `FileUploadService`'s `documents` module, which enforces an allow-list (`pdf, doc, docx, xls, xlsx, png, jpg, jpeg`), randomizes the stored filename, and saves to the `private` disk. The original filename is kept only as display metadata, never trusted as a storage path. A new authorized endpoint `GET /api/documents/{document}/download` streams the file after an ownership/visibility check; the raw `file_path` is no longer present in API responses — `DocumentResource` now exposes a `download_url` instead. The download/list endpoints continue to use `auth:sanctum` (no narrower permission existed for this feature prior to the fix; the existing `visibility` model — public/internal/department/private — plus the new ownership check govern access).
- **Backward compatibility:** the download endpoint checks the `private` disk first, then falls back to `public`, so documents uploaded before this fix remain downloadable.

### HIGH — Unrestricted file upload to public disk (`PartnerDocumentController`)
- **Files changed:** `app/Http/Controllers/Partner/PartnerDocumentController.php`, `routes/api.php`
- **Issue:** Same pattern as above — partner uploads accepted any file type and were stored publicly.
- **Fix:** Uploads now go through `FileUploadService`'s `partner_documents` module (`pdf, doc, docx, xls, xlsx`, private disk, randomized filename). A new endpoint `GET /api/partner/documents/{document}/download` checks the requesting partner user owns the document's partner before streaming it. `index()` now returns a `download_url` instead of any raw path. Same disk fallback for old public files.

### LOW — Volunteer CV files stored on public disk (defense-in-depth)
- **Files changed:** `app/Http/Controllers/Public/VolunteerRequestController.php`, `app/Http/Controllers/Admin/AdminVolunteerRequestController.php`
- **Issue:** CVs (PII) were stored on the `public` disk; access was already gated by signed admin routes and a nulled `cv_file_url` accessor, but there was no disk-level isolation backing that.
- **Fix:** New CV uploads are stored on the `private` disk. `downloadCv()`/`viewCv()` now resolve `private` first, falling back to `public` for CVs uploaded before this change, so no existing applicant's CV becomes inaccessible.
- **Validation:** unchanged — already restricted to `pdf,doc,docx`, max 5 MB, which matches the audit's recommendation.

### LOW — Assignment submission files stored on public disk
- **Files changed:** `app/Http/Controllers/Student/StudentAssignmentController.php`, `app/Http/Resources/AssignmentSubmissionResource.php`, new `app/Http/Controllers/SubmissionDownloadController.php`, `routes/api.php`
- **Issue:** Submission files were stored on `public` disk and the API returned a direct public URL to the file — no authorization check at all on the file itself.
- **Fix:** New submissions are stored on the `private` disk. A new endpoint `GET /api/submissions/{submission}/download` enforces: the submitting student can download their own submission; the instructor teaching that submission's course can download it; admin/super_admin can download any submission. `AssignmentSubmissionResource.file_url` now points at this endpoint instead of a raw public storage URL, and no longer exposes `file_path`. Old public-disk submissions remain downloadable via the same fallback pattern used elsewhere.

### Frontend HIGH — Stored XSS via unsanitized rich text (`LearningPathDetail.tsx`)
- **File changed:** `src/pages/LearningPathDetail.tsx`
- **Issue:** `path.full_description` (admin-editable, rendered on an unauthenticated public page) was rendered via `dangerouslySetInnerHTML` without sanitization, unlike the two other rich-text renderers in the app.
- **Fix:** Wrapped in `DOMPurify.sanitize()`, matching `LessonPlayer.tsx` and `KnowledgeArticlePublicPage.tsx`. Verified (by repo-wide grep) these are the only three `dangerouslySetInnerHTML` usages in the frontend and all three are now sanitized.

## Intentionally accepted risk (business decision — not a vulnerability)

> **Department manager global course access is accepted by business requirement and is not treated as a vulnerability.**
> `AdminCourseController` intentionally applies no `department_id` scoping for the `department_manager` role — by design, a department manager is permitted to view/edit courses belonging to other departments in this project. The route-comment language implying department scoping is stale and should be read in light of this decision, not as a bug.

## Remaining low-risk items (not fixed, accepted for now)

- **Frontend token storage:** the Sanctum bearer token is stored in `localStorage` (`emc_token`) rather than an httpOnly cookie. This is a standard SPA architecture trade-off and was not migrated — that would be a larger auth architecture change. Risk is mitigated by keeping all `dangerouslySetInnerHTML` usages sanitized (see above), since XSS is the only realistic path to reading `localStorage`.

## Files changed in this round of fixes

Backend:
- `app/Http/Controllers/DocumentController.php`
- `app/Http/Controllers/Partner/PartnerDocumentController.php`
- `app/Http/Controllers/SubmissionDownloadController.php` (new)
- `app/Http/Controllers/Admin/AdminVolunteerRequestController.php`
- `app/Http/Controllers/Public/VolunteerRequestController.php`
- `app/Http/Controllers/Student/StudentAssignmentController.php`
- `app/Http/Resources/DocumentResource.php`
- `app/Http/Resources/AssignmentSubmissionResource.php`
- `config/filesystems.php` (added explicit `private` disk — `FileUploadService` referenced this disk name but it did not exist in config, which would have thrown at runtime)
- `routes/api.php` (added `documents.download`, `partner.documents.download`, `submissions.download` named routes)

Frontend:
- `src/pages/LearningPathDetail.tsx`

## How to test

Manual checklist:
1. Upload a `.html` file via `POST /api/documents` → rejected (`422`, not in allow-list).
2. Upload a `.svg` file via `POST /api/documents` → rejected.
3. Upload a `.pdf` file via `POST /api/documents` → succeeds, response has `download_url`, no raw path.
4. Try `GET /api/documents/{id}/download` as a user with no relation to the document and `visibility=private`/`department` → `403`.
5. `GET /api/documents/{id}/download` as admin or the uploader → `200`, file streams.
6. Repeat 1–5 for `POST /api/partner/documents` and `GET /api/partner/documents/{id}/download`, confirming a partner from a different partner account gets `403`.
7. Submit a volunteer application with a `.pdf`/`.doc`/`.docx` CV → succeeds; admin `downloadCv`/`viewCv` still work for both newly uploaded (private disk) and pre-existing (public disk) CVs.
8. Student submits an assignment with an allowed file type → succeeds; `GET /api/submissions/{id}/download` works for that student, fails (`403`) for a different student, works for the instructor teaching that course, fails for an instructor of a different course.
9. Load `/learning-paths/:slug` for a learning path whose `full_description` contains an `<img onerror=...>` payload → script does not execute (DOMPurify strips it).

Automated:
- `php artisan route:list` — confirms `documents.download`, `partner.documents.download`, `submissions.download` are registered.
- `php -l` on every changed PHP file — no syntax errors.
- `npm run build` — frontend build succeeds with the DOMPurify import.
- No PHPUnit/Pest runner was available in this environment (`vendor/bin/phpunit` and `vendor/bin/pest` are absent, and `php artisan test` is not a registered command) — could not run an automated backend test suite as part of this change. Recommend adding feature tests for the new download endpoints' authorization logic if/when a test runner is set up.
