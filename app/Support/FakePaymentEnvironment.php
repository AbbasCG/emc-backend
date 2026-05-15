<?php

namespace App\Support;

final class FakePaymentEnvironment
{
    public static function allowed(): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        return app()->environment('testing')
            && filter_var(config('app.allow_fake_payments_in_testing', false), FILTER_VALIDATE_BOOL);
    }
}
