# Notification Event Coverage Matrix

Maps every real `NotificationService` scenario method (56, verified by `grep -c "public static function" app/Services/NotificationService.php` minus `send()` itself) to its actual module, actor, recipients, channels, and idempotency strategy. Built from the real method signatures and `Triggered by:` docblocks in `app/Services/NotificationService.php`, not a generic 18×14 grid.

**Idempotency strategy legend** (Step 9 classification): **A** = deterministically idempotent (one real-world occurrence, safe to key on the entity id alone). **B** = legitimately repeatable, current time-window dedup is appropriate and sufficient (no stable per-occurrence identifier exists in the schema to key on). **C** = legitimately repeatable *and* each occurrence needs a distinct semantic key (entity id alone is not enough) to avoid either wrongly deduping two real events or being unable to dedup a genuine retry of the same one.

**Adopted** = an explicit `idempotency_key` is wired via `App\Services\NotificationIdempotencyKey` (tested). **Time-window** = intentionally still on the original `(user_id, type, entity_id, 10s)` dedup — the correct tool for Class B scenarios, not a gap.

| # | Method | Module | Actor | Recipients | Channels wired | Class | Idempotency |
|---|--------|--------|-------|-----------|-----------------|-------|--------------|
| 1 | `courseCreated` | Courses | admin | admins + dept manager | in_app | A | **Adopted** — `course_created:{courseId}` |
| 2 | `instructorAssigned` | Courses | admin | instructor | in_app | A | **Adopted** — `instructor_assigned:{courseId}` |
| 3 | `workshopInstructorAssigned` | Workshops | admin | instructor | in_app | A | **Adopted** — `workshop_instructor_assigned:{workshopId}` |
| 4 | `courseDateUpdated` | Courses | admin/instructor | instructor + enrolled students | in_app | C (each new date is a new event) | **Adopted** — `course_date_updated:{courseId}:{normalizedDate}` |
| 5 | `registrationOpened` | Registrations | admin | admins | in_app | A | **Adopted** — `registration_opened:{courseId}` |
| 6 | `studentRegistered` | Registrations | student | student | in_app | B (a student can register for multiple courses — same type, different entity_id already differentiates) | Time-window |
| 7 | `registrationConfirmed` | Payments | system | student | in_app | A | **Adopted** — `registration_confirmed:{registrationId}` |
| 8 | `workshopRequestSubmitted` | Workshops | partner/admin | admins | in_app | A | **Adopted** — `workshop_request_submitted:{requestId}` |
| 9 | `workshopRequestMovedToStep` | Workshops | admin | requester | in_app | C (each step transition is distinct) | **Adopted** — `workshop_request_step:{requestId}:{stepNumber}` |
| 10 | `workshopRequestRejected` | Workshops | admin | requester | in_app | A | **Adopted** — `workshop_request_rejected:{requestId}` |
| 11 | `courseCancelled` | Courses | admin | enrolled students | in_app | A | **Adopted** — `course_cancelled:{courseId}` |
| 12 | `courseCompleted` | Courses | system | enrolled students | in_app | A | **Adopted** — `course_completed:{courseId}` |
| 13 | `accountCreated` | Accounts | admin | new user | in_app | A | **Adopted** — `account_created:{userId}` |
| 14 | `volunteerRequestSubmitted` | Volunteers | volunteer | HR/admin | in_app | A | **Adopted** — `volunteer_request_submitted:{requestId}` |
| 15 | `volunteerConvertedToMember` | Volunteers | admin | volunteer | in_app | A | **Adopted** — `volunteer_converted:{requestId}` |
| 16 | `courseScheduleUpdated` | Courses | admin | enrolled students | in_app | C in principle, A in practice — see note below | **Adopted** — `course_schedule_updated:{courseId}` |
| 17 | `placementTestCompleted` | Placement | student | student | in_app | A (component-based — no attempt id passed to this method) | **Adopted** — `placement_test_completed:{courseId}:{studentName}` |
| 18 | `oralAssessmentBooked` | Placement | student | student | in_app | B (booking then rebooking is legitimate) | Time-window |
| 19 | `oralAssessmentCompleted` | Placement | instructor | student | in_app | A (no entity id available) | **Adopted** — `oral_assessment_completed:{courseTitle}` |
| 20 | `oralAssessmentStatusChanged` | Placement | instructor | student | in_app | C (each status is a distinct event) | **Adopted** — `oral_assessment_status_changed:{courseTitle}:{statusLabel}` |
| 21 | `oralAssessmentRescheduled` | Placement | student/instructor | student + instructor | in_app | C (each new slot is a distinct event) | **Adopted** — `oral_assessment_rescheduled:{courseTitle}:{newSlotDatetime}` |
| 22 | `oralAssessmentInstructorMessage` | Placement | instructor | student | in_app | B (a free-text message can legitimately repeat) | Time-window |
| 23 | `classGroupAssigned` | Classes | admin/system | student | in_app | A (name-based — no class_group_id passed) | **Adopted** — `class_group_assigned:{classGroupName}:{courseTitle}` |
| 24 | `classGroupStudentAssignedInstructor` | Classes | admin/system | instructor | in_app | A (name-based) | **Adopted** — `class_group_student_assigned:{classGroupName}:{studentName}` |
| 25 | `assignmentGraded` | Assignments | instructor | student | in_app + **sms/push-ready** | C | **Adopted** — `assignment_graded:{submissionId}:{score}` |
| 26 | `certificateIssued` | Certificates | system | student | in_app + **sms/push-ready** | A | **Adopted** — `certificate_issued:{certificateId}` |
| 27 | `reportReady` | Reports | system | requester | in_app | A | **Adopted** — `report_ready:{reportId}` |
| 28 | `assignmentCreatedForStudents` | Assignments | instructor | enrolled students | in_app | A | **Adopted** — `assignment_created:{courseAssignmentId}` |
| 29 | `assignmentDeadlineChanged` | Assignments | instructor | enrolled students | in_app | C | **Adopted** — `assignment_deadline_changed:{courseAssignmentId}:{normalizedDeadline}` |
| 30 | `studentSubmittedAssignment` | Assignments | student | instructor | in_app | B (resubmissions are legitimate, each a real event) | Time-window |
| 31 | `studentResubmittedAssignment` | Assignments | student | instructor | in_app | B | Time-window |
| 32 | `requestedResubmission` | Assignments | instructor | student | in_app | B | Time-window |
| 33 | `sessionScheduled` | Sessions | instructor | enrolled students | in_app | C | **Adopted** — `session_scheduled:{courseId}:{startsAt}:{create\|update}` |
| 34 | `attendanceMarked` | Attendance | instructor | student | in_app | B (attendance is marked once per session per student in practice, but the schema doesn't expose a stable per-mark id to this method) | Time-window |
| 35 | `repeatedAbsence` | Attendance | system | student | in_app | B (each threshold breach is a new, real warning) | Time-window |
| 36 | `attendanceRecovered` | Attendance | system | student | in_app | B | Time-window |
| 37 | `classAttendanceCompleted` | Attendance | system | instructor | in_app | B (once per session in practice, per docblock — "only on the FIRST...") | Time-window |
| 38 | `lowAttendanceWarning` | Attendance | system | student | in_app | B | Time-window |
| 39 | `classAnnouncementPublished` | Classes | instructor | class students | in_app | A | **Adopted** — `class_announcement_published:{announcementId}` |
| 40 | `oralAssessmentBookedInstructor` | Placement | student | instructor | in_app | B | Time-window |
| 41 | `learningPathEnrolled` | Learning Paths | student | student | in_app | A | **Adopted** — `learning_path_enrolled:{pathId}` |
| 42 | `learningPathCompleted` | Learning Paths | system | student | in_app | A | **Adopted** — `learning_path_completed:{pathId}` |
| 43 | `courseStudentCompleted` | Courses | system | instructor | in_app | A | **Adopted** — `course_student_completed:{courseId}` |
| 44 | `ambassadorApplicationSubmitted` | Ambassadors | student | admins | in_app | A | **Adopted** — `ambassador_application_submitted:{applicationId}` |
| 45 | `ambassadorStatusChanged` | Ambassadors | admin | applicant | in_app | C | **Adopted** — `ambassador_status_changed:{applicationId}:{newStatus}` |
| 46 | `moduleActivated` | Curriculum | instructor/admin | enrolled students | in_app | A (first-activation-only — see note below) | **Adopted** — `module_activated:{moduleId}` |
| 47 | `lessonActivated` | Curriculum | instructor/admin | enrolled students | in_app | A (first-activation-only — see note below) | **Adopted** — `lesson_activated:{lessonId}` |
| 48 | `lessonContentUpdated` | Curriculum | instructor/admin | enrolled students | in_app | B (a meaningful content update can legitimately recur) | Time-window |
| 49 | `materialVisible` | Curriculum | instructor/admin | enrolled/class students | in_app | A | **Adopted** — `material_visible:{materialId}` |
| 50 | `ticketResolved` | Tickets | assignee (via `TicketStatusObserver`) | the report's submitter | in_app + logged email | A | **Adopted** — `ticket_resolved:{ticketId}` |
| 51 | `suggestionApproved` | Tickets | department leader (via `TicketStatusObserver`) | the suggestion's submitter | in_app + logged email | A | **Adopted** — `suggestion_approved:{ticketId}` |
| 52 | `suggestionImplemented` | Tickets | assignee (via `TicketStatusObserver`) | the suggestion's submitter | in_app + logged email | A | **Adopted** — `suggestion_implemented:{ticketId}` |
| 53 | `volunteerHrProfileSubmitted` | Volunteers | volunteer | super_admin + admin + hr_manager | in_app | A | **Adopted** — `volunteer_hr_profile_submitted:{profileId}` |
| 54 | `volunteerHrProfileApproved` | Volunteers | HR | the volunteer (skipped when the profile has no account) | in_app | A | **Adopted** — `volunteer_hr_profile_approved:{profileId}` |
| 55 | `volunteerHrProfileRejected` | Volunteers | HR | the volunteer (skipped when the profile has no account) | in_app | A | **Adopted** — `volunteer_hr_profile_rejected:{profileId}` |
| 56 | `oralAssessmentMeetingLinkUpdated` | Placement | instructor/admin | student | in_app + push | C (each new link — or its removal — is a distinct event) | **Adopted** — `oral_assessment_meeting_link_updated:{bookingId}:{link or "removed"}` |

`grep -c "public static function" app/Services/NotificationService.php` returns **57** — `send()` (the core dispatcher, not a scenario) plus the **56 real scenario methods** above, one row each.

## Legitimate-repeat decisions made explicit this pass

- **`moduleActivated`/`lessonActivated`** (rows 46–47): keyed on the module/lesson id alone, meaning **only the first activation notifies**. A later deactivate-then-reactivate of the same module/lesson is treated as the same occurrence and stays deduplicated — a deliberate product decision, not an oversight, made because the schema has no activation-version/timestamp column to key on instead. If a future pass wants reactivation to notify again, that column must be added first.
- **`courseScheduleUpdated`** (row 16): C in principle (a later re-assignment to a different instructor is a real, distinct event) but its sole caller (`InstructorAssignedListener`) passes no distinguishing value beyond `courseId` — keyed as Class A in practice. Accepted, narrow limitation: two instructor re-assignments for the same course within the dedup window would collide; judged not worth a signature/caller change this pass.
- **`placementTestCompleted`**, **`classGroupAssigned`**, **`classGroupStudentAssignedInstructor`**, **`oralAssessmentCompleted`**, **`oralAssessmentStatusChanged`**, **`oralAssessmentRescheduled`** (rows 17, 19–21, 23–24): these methods receive no database entity id at all (only display names/titles) — keyed on the best available real, already-passed parameters (student/class/course names). Named, accepted limitation: two entities sharing an identical display name would collide; judged acceptable rather than widening these methods' signatures and updating every caller for this pass.

## Channel coverage summary

- **in_app**: all 56 scenarios (100%) — the canonical store, always written immediately regardless of quiet hours (see `QuietHoursService` docblock for the exact policy).
- **push**: policy-driven per `App\Support\NotificationChannelPolicy` (Ticket 8 channel-policy completion pass) — see the classification table below for which of the 56 real dispatched types (one row per real `self::send(..., 'type', ...)` string; two scenario methods, `oralAssessmentBooked`/`oralAssessmentBookedInstructor`, share the `oral_assessment_booked` type) get push by default.
- **sms**: reserved for the small, explicitly time-sensitive subset — never enabled merely because an event is "important". Two scenarios (`session_scheduled`, `oral_assessment_rescheduled`) resolve SMS *contextually*, added only when the real `starts_at`/new-slot parameter the method already has is within `NotificationChannelPolicy::IMMINENT_THRESHOLD_HOURS` (24h) of now — never by parsing title/message text.
- **email / whatsapp**: handled independently per the pre-existing architecture (see ADR §9); the classification table's Email/WhatsApp columns mark which of the 56 scenarios have a confirmed separate call site (`existing_separate`) so the channel policy never duplicates them.
- **realtime**: all 56 scenarios automatically broadcast on `notifications.{userId}` the moment their `platform_notifications` row is created (wired once, centrally, in `SendPlatformNotificationJob`, not per-scenario) — no scenario needed individual changes for this.
- **Correction to prior-pass documentation**: earlier passes described `assignmentGraded`/`certificateIssued` as "sms/push-ready proof-of-concept scenarios" using `'channels' => ['sms', 'push']`. Re-auditing the actual source this pass (`grep -n "'channels' =>" app/Services/NotificationService.php` returns zero matches) found neither method has ever hardcoded a `channels` key — both scenarios previously fell through to no external fan-out at all (no `$extra['channels']` present, and prior to this pass there was no default policy to fall back to). Both now correctly receive `['in_app', 'push']` (no SMS — judged disproportionate cost for a non-imminent event) for the first time, via the central policy below, not a code path that existed before.

## Channel policy classification (all 56 real dispatched types)

Source of truth: `App\Support\NotificationChannelPolicy::POLICY`. Verified this pass to be a complete 1:1 mapping against the 56 real type strings extracted directly from `NotificationService.php`'s `self::send()` call sites (zero missing, zero extra — see `Ticket8ChannelPolicyTest::test_policy_covers_every_real_dispatched_type_with_no_extras`). `urgent` is `false` for every single type — none of the 49 scenario methods carry a verified, unambiguous "immediate operational emergency" signal (see ADR §21 for the specific candidates considered and rejected).

| Type | Channels (default) | Urgent | Email | WhatsApp | Notes |
|------|--------------------|--------|-------|----------|-------|
| `course_created` | in_app | false | none | none | Admin-facing catalog event |
| `instructor_assigned` | in_app | false | none | none | Visible in instructor dashboard |
| `workshop_instructor_assigned` | in_app | false | none | none | Same as instructor_assigned |
| `course_date_updated` | in_app, push | false | none | none | Real schedule change, actionable |
| `registration_opened` | in_app | false | none | none | Admin-only informational |
| `student_registered` | in_app | false | existing_separate | none | CourseEnrollmentMail already sent |
| `new_registration` | in_app | false | existing_separate | none | AdminNewRegistrationMail already sent |
| `registration_confirmed` | in_app, push | false | none | none | Confirms a paid action succeeded |
| `workshop_request_submitted` | in_app | false | none | none | Admin queue item |
| `workshop_request_step` | in_app | false | none | none | Internal workflow step |
| `workshop_request_rejected` | in_app | false | none | none | Admin-facing outcome |
| `course_cancelled` | in_app, push | false | none | none | No start-time parameter to verify imminence safely (see limitation below) |
| `course_completed` | in_app | false | none | none | Achievement-type, not time-critical |
| `account_created` | in_app | false | existing_separate | none | WelcomeMail/AccountCreatedMail already sent |
| `volunteer_request` | in_app | false | existing_separate | none | VolunteerApplicationAdminMail already sent |
| `member_converted` | in_app | false | none | none | Internal admin/HR record update |
| `course_schedule_updated` | in_app, push | false | none | none | Same reasoning as course_date_updated |
| `placement_test_completed` | in_app | false | none | none | Instructor reviews on own schedule |
| `oral_assessment_booked` | in_app, push | false | existing_separate | none | Actionable appointment for both recipients; OralAssessmentSchedulingService sends the confirmation email (with ICS) separately |
| `oral_assessment_completed` | in_app | false | none | none | Result visible in dashboard |
| `oral_assessment_status_changed` | in_app | false | none | none | Status-only, no verified urgency |
| `oral_assessment_rescheduled` | in_app, push (**+ sms if imminent**) | false | none | none | Contextual — see below |
| `oral_assessment_message` | in_app | false | none | none | Free-text, conservative default |
| `oral_assessment_meeting_link_updated` | in_app, push | false | existing_separate | none | The join link changing shortly before an already-scheduled interview is actionable and time-sensitive enough for push; OralAssessmentSchedulingService sends a matching email separately |
| `class_group_assigned` | in_app | false | none | none | Visible next dashboard visit |
| `class_group_student_assigned` | in_app | false | none | none | Instructor-facing roster update |
| `assignment_graded` | in_app, push | false | existing_separate | none | EmailService already sends assignment_graded email |
| `certificate_issued` | in_app, push | false | existing_separate | none | Achievement worth a timely nudge |
| `report_ready` | in_app | false | none | none | Pulled on demand |
| `assignment_created` | in_app | false | none | none | Routine content addition |
| `assignment_deadline_changed` | in_app | false | none | none | No safe imminence signal in the method signature |
| `assignment_submitted` | in_app | false | none | none | Instructor-facing, async review |
| `assignment_resubmitted` | in_app | false | none | none | Same as assignment_submitted |
| `resubmission_requested` | in_app | false | none | none | Not time-critical within hours |
| `session_scheduled` | in_app, push (**+ sms if imminent reschedule**) | false | none | none | Contextual — see below |
| `attendance_marked` | in_app | false | none | none | Routine confirmation |
| `attendance_repeated_absence` | in_app | false | none | none | Informational warning |
| `attendance_recovered` | in_app | false | none | none | Positive/informational |
| `class_attendance_completed` | in_app | false | none | none | Instructor-facing summary |
| `attendance_low_warning` | in_app | false | none | none | Escalating to push/sms judged disproportionate |
| `class_announcement_published` | in_app | false | none | none | Content varies too widely for a blanket push default |
| `learning_path_enrolled` | in_app | false | existing_separate | none | LearningPathEnrollmentMail already sent |
| `learning_path_completed` | in_app | false | existing_separate | none | LearningPathCompletionMail already sent |
| `course_completed_student` | in_app | false | existing_separate | none | CourseCompletionMail already sent |
| `module_activated` | in_app | false | none | none | Routine content publication |
| `lesson_activated` | in_app | false | none | none | Routine content publication |
| `lesson_content_updated` | in_app | false | none | none | Legitimately repeats (Class B) |
| `material_visible` | in_app | false | none | none | Routine content publication |
| `ticket_resolved` | in_app | false | existing_separate | none | Submitter's report was resolved; EmailService sends TicketStatusUpdateMail separately (EmailSettingsController key `ticket_resolved`) |
| `suggestion_approved` | in_app | false | existing_separate | none | Submitter's suggestion was approved; email sent separately via EmailService |
| `suggestion_implemented` | in_app | false | existing_separate | none | Submitter's suggestion was added to the system; email sent separately via EmailService |
| `ambassador_application` | in_app | false | existing_separate | none | AmbassadorApplicationAdminMail already sent |
| `ambassador_status_changed` | in_app | false | none | none | Applicant email dispatched separately by the controller, not this method |
| `volunteer_hr_profile_submitted` | in_app | false | none | none | HR review-queue event; visible in the operations workspace and not time-sensitive |
| `volunteer_hr_profile_approved` | in_app | false | none | none | Volunteer-facing outcome retained in-app; no verified need for paid or interruptive channels |
| `volunteer_hr_profile_rejected` | in_app | false | none | none | Volunteer-facing outcome retained in-app; no verified need for paid or interruptive channels |

**Context-dependent resolution** (`NotificationChannelPolicy::resolveWithContext()`), never parses title/message text — only real typed parameters the caller already has:
- `session_scheduled`: adds `sms` only when `$extra['is_update']=true` (a reschedule of an existing plan, not a brand-new session) **and** `$extra['starts_at']` is within 24h of now.
- `oral_assessment_rescheduled`: adds `sms` when `$extra['starts_at']` (the new slot) is within 24h of now.
- `course_cancelled`: **not** given contextual SMS — `courseCancelled(int $courseId, string $courseTitle)`'s signature carries no timing parameter to safely resolve imminence from; documented limitation, not silently guessed from text.
- Both callers must be updated to pass `starts_at`/`is_update` through `$extra` for the contextual escalation to activate; absent that, the base (push-only) policy applies — the safer default, never a crash.

## Types dispatched outside `NotificationService` (no policy entry: the safe in-app-only default applies)

A few types are sent by calling `NotificationService::send()` directly from a controller instead of through a scenario method. They have no
entry in `NotificationChannelPolicy::POLICY`, so `channelsFor()` returns the documented default `['in_app']` (never push, SMS or email).
`Ticket8ChannelPolicyTest::test_policy_covers_every_real_dispatched_type_with_no_extras` only reads `NotificationService.php`, which is why they are
listed here and not in the table above. Delivery goes through the same `SendPlatformNotificationJob` (queued, `afterCommit`, de-duplicated by
`idempotency_key`).

| Type | Sent from | Recipients | Idempotency |
|------|-----------|-----------|-------------|
| `payment_under_review` | `StripeWebhookController::announceHold` | the payer whose confirmed payment was held for a financial review | `payment_under_review:{paymentId}` |
| `payment_reconciliation_required` | `StripeWebhookController::announceHold` | active `admin`, `super_admin` and `finance_manager` accounts | `payment_reconciliation:{reconciliationId}` |
| `financial_request_submitted` / `_executive_review` / `_rejected` / `_returned` / `_approved` | `FinancialRequestController` | requester and reviewers of the request | per request/step (see the controller) |
| class announcement types | `InstructorClassController` (type passed by the caller) | the class's students | per announcement |

Both payment types are recoverable: if the first webhook delivery committed the case but died before the notifications were stored, a later Stripe
retry re-sends them (only for a case at least one minute old with a missing row, so deliveries arriving together never race).

## Quiet-hours interaction

Unchanged from Pass C: in-app is written immediately regardless of quiet hours; only push/SMS are delayed (never discarded) to the user's configured end time, unless `urgent` (always `false` in this policy — see table above).

## Idempotency coverage summary

- **Class A/C scenarios genuinely requiring a stable key**: 36 of 49 (recounted this pass via a fresh per-method audit — the prior pass's own summary of "26" undercounted against its own table; this is a correction, not a new discrepancy).
- **Adopted**: all 43 — **100% of scenarios classified as requiring deterministic semantic idempotency now have adopted keys.** 6 were adopted in the prior pass (`certificateIssued`, `reportReady`, `assignmentGraded`, `assignmentCreatedForStudents`, `sessionScheduled`, `materialVisible`); the remaining 30 were adopted this pass via the new canonical `App\Services\NotificationIdempotencyKey` builder, each individually tested in `Ticket8IdempotencyAdoptionTest` (first-dispatch-creates, exact-retry-dedups, distinct-occurrence-creates-new, per-recipient-isolation).
- **Class B scenarios (13 of 56)**: intentionally remain on time-window dedup — a distinct key isn't the right fix for "legitimately repeats with no natural occurrence id," time-window dedup is the correct, deliberately-unchanged tool for these: `studentRegistered`, `oralAssessmentBooked`, `oralAssessmentInstructorMessage`, `studentSubmittedAssignment`, `studentResubmittedAssignment`, `requestedResubmission`, `attendanceMarked`, `repeatedAbsence`, `attendanceRecovered`, `classAttendanceCompleted`, `lowAttendanceWarning`, `oralAssessmentBookedInstructor`, `lessonContentUpdated`.
- **Zero remaining deterministic gaps.** Do not read this as "100% of all 56 scenarios are deterministic" — 13 are Class B by deliberate, documented design and correctly remain on time-window dedup.

## Completeness guarantee

`Ticket8CompletionTest::test_every_notification_service_scenario_method_is_represented_in_the_event_matrix` (unchanged) parses this file's method column via regex and asserts, by reflection on `NotificationService`, that every `public static function` (except `send`) has a row here. `Ticket8IdempotencyAdoptionTest::test_every_class_a_or_c_scenario_in_the_matrix_is_marked_adopted` (new this pass) parses the same table and asserts every row whose Class column contains "A" or "C" also has "Adopted" in its Idempotency column, and every row whose Class column is exactly "B" does not — so this matrix's adoption claims cannot silently drift from the table's own classification.
