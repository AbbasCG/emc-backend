<?php

namespace App\Enums;

enum CourseStatus: string
{
    case DRAFT     = 'draft';
    case PUBLISHED = 'published';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';
    case INACTIVE  = 'inactive';
}
