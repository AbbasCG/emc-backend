<?php

namespace App\Enums;

enum NotificationType: string
{
    case COURSE_CREATED               = 'course_created';
    case COURSE_UPDATED               = 'course_updated';
    case COURSE_CANCELLED             = 'course_cancelled';
    case COURSE_COMPLETED             = 'course_completed';
    case COURSE_DATE_UPDATED          = 'course_date_updated';
    case REGISTRATION_CONFIRMED       = 'registration_confirmed';
    case STUDENT_REGISTERED           = 'student_registered';
    case INSTRUCTOR_ASSIGNED          = 'instructor_assigned';
    case WORKSHOP_INSTRUCTOR_ASSIGNED = 'workshop_instructor_assigned';
    case WORKSHOP_REQUEST_SUBMITTED   = 'workshop_request_submitted';
    case REGISTRATION_OPENED          = 'registration_opened';
    case GENERAL                      = 'general';
}
