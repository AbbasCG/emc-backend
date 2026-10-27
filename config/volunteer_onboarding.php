<?php

/*
|--------------------------------------------------------------------------
| Volunteer onboarding: automatic account / role / membership changes
|--------------------------------------------------------------------------
|
| Each switch below makes the system change an account, a role or a department membership on its own, as a side effect of an
| administrative action. All of them are OFF until the business explicitly approves the rule (the previous behaviour — no
| side effect — is what runs when they are off). Enable one by setting the matching environment variable to true.
|
*/

return [
    // Approving a volunteer's HR profile for a department turns a `student` / `volunteer` account into `department_member`.
    'approval_assigns_department_member_role' => (bool) env('VOLUNTEER_APPROVAL_ASSIGNS_DEPARTMENT_MEMBER_ROLE', false),

    // Approving it also attaches the account to that department and seats it (unassigned) in the team.
    'approval_links_department_membership' => (bool) env('VOLUNTEER_APPROVAL_LINKS_DEPARTMENT_MEMBERSHIP', false),

    // Converting an accepted volunteer request into a team member also creates the user account (random password,
    // `department_member` role) when none exists, and links/seats it in the department.
    'conversion_creates_account' => (bool) env('VOLUNTEER_CONVERSION_CREATES_ACCOUNT', false),
];
