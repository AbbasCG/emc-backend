<?php

namespace App\Support\PageAccess;

use InvalidArgumentException;

/**
 * A single immutable Page Access Catalog entry.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION. This describes whether a dashboard
 * page/capability may be exposed to a user; it never replaces backend
 * permissions, policies, DepartmentAccessService, or controller authorization.
 *
 * A capability's stable `key` is its identity — never a URL. Many active
 * routes are index/show/create/edit variants (or alternate mount points) of
 * the same logical access surface, so a capability may cover multiple URLs
 * via `routePatterns`. `primaryRoute` is always the first element of
 * `routePatterns` and is what a future UI should deep-link to by default.
 */
final class PageAccessEntry
{
    /** @var array<int, string> */
    public readonly array $routePatterns;

    /**
     * @param array<int, string> $routePatterns Additional route patterns beyond $primaryRoute
     *   that belong to this same capability (index/show/create/edit variants, or alternate
     *   mount points rendering the same page). $primaryRoute is always included automatically
     *   and always appears first, so pass only the extras here.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $primaryRoute,
        public readonly string $labelAr,
        public readonly PageAccessCategory $category,
        public readonly PageAccessRiskLevel $riskLevel,
        public readonly bool $delegatable,
        public readonly ?string $requiredPermission = null,
        public readonly bool $departmentScoped = false,
        array $routePatterns = [],
        /**
         * Phase 2G.2 — AUTHENTICATED BASELINE.
         *
         * True when this capability belongs to any authenticated dashboard
         * account regardless of role, department membership or leadership.
         * Owned here in the catalog rather than duplicated into every role's
         * defaults, so a NEW role inherits it automatically and no admin can
         * remove someone's own profile page by editing a role.
         *
         * Baseline still grants PAGE ACCESS ONLY — see the class docblock.
         */
        public readonly bool $authenticatedBaseline = false,
    ) {
        if ($this->riskLevel === PageAccessRiskLevel::SystemProtected && $this->delegatable) {
            throw new InvalidArgumentException(
                "Page access entry [{$this->key}] is SYSTEM_PROTECTED and cannot be marked delegatable."
            );
        }

        // A root-authority surface can never be handed to every authenticated
        // account. Enforced in the constructor so no future catalog edit can
        // introduce the combination, not even by accident.
        if ($this->riskLevel === PageAccessRiskLevel::SystemProtected && $this->authenticatedBaseline) {
            throw new InvalidArgumentException(
                "Page access entry [{$this->key}] is SYSTEM_PROTECTED and cannot be an authenticated baseline."
            );
        }

        $merged = array_values(array_unique([$this->primaryRoute, ...$routePatterns]));
        foreach ($merged as $pattern) {
            if ($pattern === '' || ! str_starts_with($pattern, '/dashboard')) {
                throw new InvalidArgumentException(
                    "Page access entry [{$this->key}] has an invalid route pattern [{$pattern}] — must be a non-empty /dashboard path."
                );
            }
        }
        $this->routePatterns = $merged;
    }

    /** True only for SYSTEM_PROTECTED entries — derived, never set independently, so it can never drift from risk_level. */
    public function isProtected(): bool
    {
        return $this->riskLevel === PageAccessRiskLevel::SystemProtected;
    }

    /** Whether a given dashboard URL falls under this capability's route patterns (static prefix match; no dynamic-segment matching). */
    public function matchesRoute(string $route): bool
    {
        return in_array($route, $this->routePatterns, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'primary_route' => $this->primaryRoute,
            'route_patterns' => $this->routePatterns,
            'label_ar' => $this->labelAr,
            'category' => $this->category->value,
            'category_label_ar' => $this->category->labelAr(),
            'risk_level' => $this->riskLevel->value,
            'protected' => $this->isProtected(),
            'delegatable' => $this->delegatable,
            'required_permission' => $this->requiredPermission,
            'department_scoped' => $this->departmentScoped,
            'authenticated_baseline' => $this->authenticatedBaseline,
        ];
    }
}
