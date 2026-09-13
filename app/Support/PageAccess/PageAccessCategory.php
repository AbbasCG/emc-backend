<?php

namespace App\Support\PageAccess;

/**
 * Canonical dashboard page categories for the Page Access Catalog.
 * Adjust only when actual dashboard routes clearly require a new category.
 */
enum PageAccessCategory: string
{
    case Operations = 'operations';
    case Finance = 'finance';
    case Hr = 'hr';
    case Quality = 'quality';
    case Lms = 'lms';
    case OrganizationalDepartments = 'organizational_departments';
    case Administration = 'administration';
    case TechnicalSystem = 'technical_system';
    case Personal = 'personal';

    public function labelAr(): string
    {
        return match ($this) {
            self::Operations => 'العمليات',
            self::Finance => 'المالية',
            self::Hr => 'الموارد البشرية',
            self::Quality => 'الجودة',
            self::Lms => 'نظام إدارة التعلم',
            self::OrganizationalDepartments => 'الإدارات التنظيمية',
            self::Administration => 'الإدارة والنظام',
            self::TechnicalSystem => 'المنصة التقنية',
            self::Personal => 'شخصي',
        };
    }
}
