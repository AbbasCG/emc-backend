<?php

/*
|--------------------------------------------------------------------------
| Volunteer onboarding
|--------------------------------------------------------------------------
|
| A volunteer becomes a member through ONE path only: the public volunteer request (`volunteer_requests`) is accepted by HR and
| then converted to a member, and the conversion creates the account, the department membership, the team profile and the
| volunteer-hub profile in one transaction. That flow is unconditional and has no switch here.
|
| The old second path (the volunteer fills in an HR profile, `volunteer_hr_profiles`, and HR approves it) is cancelled. Its code and
| data are kept untouched; `hr_profile_pipeline_enabled` below turns its WRITE endpoints off (HTTP 410) and is off by default.
| Reading historical HR profiles keeps working either way.
|
| The three `approval_*` / `conversion_*` switches are the guards of the OLD behaviour. `approval_*` still gate
| VolunteerApprovalService::approve (the legacy HR-profile approval). `conversion_creates_account` is no longer read by
| convert-to-member (it always creates/links the account now); it is kept only so an existing .env entry does not break.
|
*/

return [
    // Master switch of the cancelled HR-profile pipeline (volunteer submits/edits an HR profile; HR reviews/approves/rejects it).
    // OFF (default): those write endpoints answer 410. ON: the legacy behaviour, unchanged.
    'hr_profile_pipeline_enabled' => (bool) env('VOLUNTEER_HR_PROFILE_PIPELINE_ENABLED', false),

    // LEGACY (HR-profile approval only): approving a volunteer's HR profile for a department turns a `student` / `volunteer`
    // account into `department_member`.
    'approval_assigns_department_member_role' => (bool) env('VOLUNTEER_APPROVAL_ASSIGNS_DEPARTMENT_MEMBER_ROLE', false),

    // LEGACY (HR-profile approval only): approving it also attaches the account to that department and seats it (unassigned) in the team.
    'approval_links_department_membership' => (bool) env('VOLUNTEER_APPROVAL_LINKS_DEPARTMENT_MEMBERSHIP', false),

    // DEPRECATED, no longer read: convert-to-member always creates (or links) the account now.
    'conversion_creates_account' => (bool) env('VOLUNTEER_CONVERSION_CREATES_ACCOUNT', false),
];
