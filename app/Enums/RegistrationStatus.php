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

    /** All statuses that grant access to course content. */
    public static function activeEnrollment(): array
    {
        return [
            self::REGISTERED->value,
            self::PAYMENT_CONFIRMED->value,
            self::ATTENDED->value,
        ];
    }
}
