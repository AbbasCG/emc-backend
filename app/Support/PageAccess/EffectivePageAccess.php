<?php

namespace App\Support\PageAccess;

/**
 * Phase 2D — the resolved verdict for ONE page, for ONE user.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION. `allowed = true` means the dashboard
 * page may be opened. It proves nothing about creating, editing, approving or
 * deleting anything on it — that remains Permissions / policies / Gates /
 * DepartmentAccessService, none of which consult this object.
 *
 * Immutable, and deliberately carries provenance rather than a bare boolean:
 * the future Access Control UI has to answer "why does this user see this
 * page?" without re-deriving the rule client-side.
 */
final class EffectivePageAccess
{
    /**
     * @param array<int, PageAccessSource> $sources every POSITIVE layer that
     *   contributed, in canonical ascending-precedence order (role,
     *   department_member, department_leader, user_allow). A DENY is NOT a
     *   positive contributor: it is reported via $overrideState and
     *   $primarySource, while $sources keeps showing what the user WOULD have
     *   inherited — which is exactly what the UI must explain.
     */
    public function __construct(
        public readonly string $key,
        public readonly bool $allowed,
        public readonly PageAccessSource $primarySource,
        public readonly array $sources,
        public readonly ?UserPageAccessState $overrideState,
        public readonly bool $isProtected,
        public readonly string $reason,
    ) {
    }

    /** @return array<int, string> */
    public function sourceValues(): array
    {
        return array_map(fn (PageAccessSource $s) => $s->value, $this->sources);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'allowed' => $this->allowed,
            'primary_source' => $this->primarySource->value,
            'primary_source_label_ar' => $this->primarySource->labelAr(),
            'sources' => $this->sourceValues(),
            'override_state' => $this->overrideState?->value,
            'protected' => $this->isProtected,
            'reason' => $this->reason,
        ];
    }
}
