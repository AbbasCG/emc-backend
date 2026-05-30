<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING   = 'pending_payment';
    case CONFIRMED = 'payment_confirmed';
    case FAILED    = 'payment_failed';
}
