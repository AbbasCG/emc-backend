<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hosts a deployment ticket's live page may belong to
    |--------------------------------------------------------------------------
    |
    | `mark-deployed` records the https page the assignee must test on the live site. Besides the always-on rules (https
    | only, no user:password@, no IP address, no localhost or single-label host, at most 500 characters), when this list is
    | NOT empty the URL's host must equal one of these hosts or be a subdomain of one. Empty (the default) = any public https
    | host.
    |
    | Set it with a comma separated environment value, e.g. TICKETS_LIVE_HOSTS="edumc.nl,emc-center.org".
    |
    */
    'live_hosts' => array_values(array_filter(array_map(
        fn ($host) => strtolower(trim((string) $host)),
        explode(',', (string) env('TICKETS_LIVE_HOSTS', ''))
    ))),

];
