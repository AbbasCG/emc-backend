<?php

/*
|--------------------------------------------------------------------------
| Department leader → specialised manager role
|--------------------------------------------------------------------------
|
| A user whose account role is `department_manager` AND who is the recorded leader (departments.leader_id) of an ACTIVE
| department additionally acts with that department's specialised manager role (e.g. the leader of "finance" acts as
| `finance_manager`). Nothing else grants it: not membership of the department, not a deputy slot, not the free-text
| `users.department` column, not the department's name.
|
| `privileged_roles` can NEVER be obtained through a department. Leading the "technology" or "executive-management"
| department therefore gives no technical or executive authority. Those roles are assigned explicitly (users.role) by a
| super admin. Both guards below must be changed deliberately, in code review, to ever allow it.
|
*/

return [
    // department slug => specialised role
    'leader_roles' => [
        'finance'              => 'finance_manager',
        'human-resources'      => 'hr_manager',
        'marketing-media'      => 'marketing_manager',
        'quality-governance'   => 'quality_manager',
        'programs-tracks'      => 'programs_manager',
        'operations'           => 'operations_manager',
        'ai-transformation'         => 'ai_manager',
        'ai-digital-transformation' => 'ai_manager',
        'community-health'     => 'community_manager',
        'partnerships'         => 'partnerships_manager',
        'strategy-planning'    => 'strategy_planning_manager',
        'digital-ambassadors'  => 'digital_ambassadors_manager',
        'advisors'             => 'advisors_manager',
    ],

    // Never reachable through a department, whatever `leader_roles` says (defence in depth against a future map edit).
    'privileged_roles' => ['super_admin', 'admin', 'tech_admin', 'executive_admin'],

    // Explicit system rule required to lift the block above. Intentionally not read from the environment.
    'allow_privileged_roles' => false,
];
