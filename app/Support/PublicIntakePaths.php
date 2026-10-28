<?php

namespace App\Support;

/**
 * Guest-only intake endpoints that skip CSRF verification.
 *
 * Sanctum's stateful pipeline (statefulApi) verifies CSRF for any browser request coming from the SPA's own origin. A visitor
 * who is not signed in has no CSRF cookie, so these public forms would answer 419 to the SPA that serves them. They act on
 * nobody's identity, so there is nothing for a forged cross-site request to abuse.
 *
 * Keep this list to EXACT paths of unauthenticated POST routes. PublicIntakePathsTest fails when a path here also matches a
 * route that requires authentication.
 */
final class PublicIntakePaths
{
    public const CSRF_EXEMPT = [
        'api/contact',
        'api/support-tickets',
        'api/workshop-requests',
        'api/volunteer-requests',
        'api/ambassador-applications',
        'api/consultant-applications',
        'api/expert-applications',
        'api/trainer-applications',
        'api/forms/*/submit',
    ];
}
