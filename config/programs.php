<?php

/*
|--------------------------------------------------------------------------
| Programs (courses, workshops, learning paths)
|--------------------------------------------------------------------------
|
| `require_dates`: make start_date and end_date mandatory when an administrator creates a course, workshop or learning path.
| Off until the business approves the rule: programs were always allowed without dates (their `nullable` columns and the
| existing API contract), and a program without an end date simply never counts as ended.
|
*/

return [
    'require_dates' => (bool) env('PROGRAMS_REQUIRE_DATES', false),
];
