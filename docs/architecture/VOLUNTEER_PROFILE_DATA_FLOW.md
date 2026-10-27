# Volunteer profile — data flow (HR → volunteer → team)

Established from the code (October 2026), not assumed. It describes what each of the three screens reads, where an edit is
stored, and how it reaches the others.

## The records

| Record | Table | Created by | Holds |
|---|---|---|---|
| Account | `users` | registration / admin | sign-in identity: name, e-mail, password, role |
| **HR profile** | `volunteer_hr_profiles` | the volunteer, at `/dashboard/volunteer/hr-profile` | the volunteer's full HR data (personal, contact, volunteering, qualifications, consents, CV, emergency contact) and the review status |
| Team record | `team_profiles` | **approval** (`VolunteerApprovalService::approve`) or admin Team CRUD | what Team Management and the public team page show: name, position, department, contact, visibility, leadership, order |
| Operational membership | `team_members` | Team Management | department / unit / role title used by operations |
| Public application | `volunteer_requests` | the public form `/volunteer/apply` | a separate intake pipeline (see below) |

Links: `volunteer_hr_profiles.user_id → users`; on approval `volunteer_hr_profiles.team_profile_id → team_profiles` **and**
`team_profiles.source = 'volunteer_hr_profile'`, `team_profiles.source_id → volunteer_hr_profiles`; `team_members.team_profile_id →
team_profiles`. A team row is treated as belonging to an HR profile only when both sides point at each other
(`VolunteerProfileSyncService::linkedTeamProfile`).

## The three screens

| Screen | Component | Reads | Writes |
|---|---|---|---|
| `/dashboard/hr/volunteers` (+`/:id`) | `HrVolunteerProfilesPage` + `HrVolunteerProfileDetailModal` | `GET /api/hr/volunteer-hr-profiles[/{id}]` → `volunteer_hr_profiles` | review, approve, reject, **edit (new)** |
| the volunteer's own data | `/dashboard/volunteer/hr-profile` (`VolunteerHrProfilePage`) | `GET /api/volunteer/hr-profile` → the same `volunteer_hr_profiles` row | the volunteer, only while the profile is a draft or rejected |
| `/dashboard/super-admin/crud/team` | `TeamManagementPage` | `GET /api/admin/team-profiles` → `team_profiles` (+ `team_members`) | full CRUD |

Two facts that the brief's page list does not show:

- `/dashboard/volunteer` is **«المتطوعون المقبولون»** (`AcceptedVolunteersPage`), a staff list of accepted **public applications**
  (`volunteer_requests` with status `accepted`, not yet converted to members). It is not the volunteer's own account and it never lists
  HR-profile volunteers. HR-profile edits therefore do not appear there, by design: those application rows are a historical intake
  record of another pipeline. The volunteer's own data page is `/dashboard/volunteer/hr-profile`.
- `App.tsx` registers `/dashboard/hr/volunteers` twice; React Router resolves the first (`HrVolunteerProfilesPage`), so the second
  (`HrVolunteersPage`, reading `GET /api/hr/volunteers` → `volunteer_profiles`) is unreachable. Verified in the browser.

## Why HR could not edit before

The «تعديل معلومات التطوع» button in the HR detail modal only showed a toast ("editing happens from the team file…"), and the
backend had no HR update endpoint: HR could review, approve or reject, nothing else. Approval copied the HR fields into
`team_profiles` once; afterwards nothing kept the two copies aligned, so an edit on either side left the other stale.

## The flow now

```
HR edit (modal form)
  → PUT /api/hr/volunteer-hr-profiles/{id}        HrVolunteerProfileController::update
      policy VolunteerHrProfilePolicy::hrUpdate   (manage_volunteers; super/tech admin via Gate::before)
      UpdateVolunteerHrProfileRequest             (explicit whitelist, Arabic messages)
      one transaction, row lock, stale-copy check (409), duplicate e-mail check
      → volunteer_hr_profiles                      (source of truth)
      → VolunteerProfileSyncService::pushToTeam    (linked team row only, shared fields only)
          → team_profiles
          → team_members                           (department / role title, unit cleared if it left the department)
      → AuditLogService                            (changed field names only, no personal values)
  ← the saved profile; the modal shows it, the HR list refreshes

Volunteer's page  GET /api/volunteer/hr-profile  → same row → shows the change on next load
Team page         GET /api/admin/team-profiles   → team_profiles → shows the shared fields on next load

Team edit of a volunteer-sourced row (PUT /api/admin/team-profiles/{id})
  → VolunteerProfileSyncService::pullFromTeam     (same field map, back to the HR profile; an empty value never erases a required HR field)
```

No screen caches these responses, so every page shows the change the next time it loads (no sign-out needed).

## Field by field

| Field (HR profile) | Editable by HR | Mirrored to `team_profiles` | Shown on |
|---|---|---|---|
| `full_name` | yes | `name_ar` | HR, volunteer page, team page |
| `email` (+`normalized_email`) | yes, unique among open/approved profiles | `email` | HR, volunteer page, team page — **not** `users.email` (sign-in) |
| `phone`, `phone_country_code` | yes | `phone` | HR, volunteer page, team page |
| `country`, `country_code`, `city` | yes | `country`, `city` | HR, volunteer page, team page |
| `gender` | yes (male/female, or the legacy value already stored) | `gender` | HR, volunteer page |
| `department_id` | yes (active departments) | `department_id` (+ `team_members`) | all three |
| `job_title` | yes | `position_ar` (+ `team_members.role_title`) | all three |
| `join_date` | yes | `joined_at` | HR, volunteer page, team page |
| `skills` | yes | `skills` | HR, volunteer page |
| `date_of_birth`, `nationality`, `employment_type`, `availability`, `weekly_hours`, `languages`, `education`, `university_specialization`, `experience`, `professional_bio`, `linkedin_url`, `portfolio_url`, `emergency_contact_name`, `emergency_contact_phone` | yes | no (HR-only data) | HR, volunteer page |
| `status`, `reviewed_*`, `approved_*`, `rejection_reason` | no — review workflow only | — | — |
| `user_id`, `team_profile_id` | no | — | — |
| `photo_publication_consent`, `professional_profile_consent`, `motivation` | no — the volunteer's own decisions and words | — | — |
| `cv_*`, `profile_photo_path` | no — uploaded files | — | — |

Team-only facts are never touched from the HR side: `is_active` (shown on the team page or not), `is_leader`, `is_executive`,
`sort_order`, `role_key`, `member_type`, the image. An HR edit never creates a team record (only approval does), never adds anyone to
the team, and never changes a role or permission.

## Statuses HR may edit

`submitted`, `under_review`, `approved`. A `draft` or `rejected` profile is with the volunteer (they edit and resubmit it); an
`archived` one is frozen. The same rule is applied in the screen and on the server.
