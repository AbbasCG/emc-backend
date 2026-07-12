<?php

namespace App\Enums;

enum RegistrationStatus: string
{
    case PENDING_PAYMENT   = 'pending_payment';
    case REGISTERED        = 'registered';
    case PAYMENT_CONFIRMED = 'payment_confirmed';
    case ATTENDED          = 'attended';
    case NO_SHOW           = 'no_show';
    case CANCELLED         = 'cancelled';

    /** All statuses that grant access to course content (normalized + legacy aliases). */
    public static function activeEnrollment(): array
    {
        return [
            self::REGISTERED->value,
            self::PAYMENT_CONFIRMED->value,
            self::ATTENDED->value,
            // Legacy values written before status normalization (still present in production DBs).
            'Registered',
            'Payment Confirmed',
            'Attended',
        ];
    }

    /** Statuses that exclude a registration from student-facing course lists. */
    public static function inactiveEnrollment(): array
    {
        return [
            self::PENDING_PAYMENT->value,
            self::NO_SHOW->value,
            self::CANCELLED->value,
            'Pending Payment',
            'Cancelled',
            'No Show',
        ];
    }
}
