<?php

namespace App\Support\PageAccess;

/**
 * Preliminary security classification for a Page Access Catalog entry.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION — this only describes how freely a
 * page/capability may be exposed or delegated. It never replaces backend
 * permissions, policies, DepartmentAccessService, or controller authorization.
 */
enum PageAccessRiskLevel: string
{
    /** Legitimate delegation candidate — may be exposed to department leaders/managers. */
    case SafeDelegatable = 'SAFE_DELEGATABLE';

    /** Restricted to admin-tier roles (admin/tech_admin/super_admin); never auto-delegated to department leaders. */
    case AdminOnly = 'ADMIN_ONLY';

    /** Root-authority / platform-security surface. Never delegatable, regardless of actor. */
    case SystemProtected = 'SYSTEM_PROTECTED';
}
