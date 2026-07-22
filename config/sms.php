<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "twilio", "log"
    |
    | "log"    — No real messages sent; writes to Laravel log. Safe default.
    | "twilio" — Twilio SMS. Uses its OWN dedicated credentials below — never
    |            assume the WhatsApp Twilio account (config/whatsapp.php) is
    |            authorized to send plain SMS; some Twilio accounts are
    |            WhatsApp-sandbox-only and cannot send SMS at all.
    |
    */
    'provider' => env('SMS_PROVIDER', 'log'),

    'twilio' => [
        'sid'   => env('SMS_TWILIO_SID'),
        'token' => env('SMS_TWILIO_TOKEN'),
        'from'  => env('SMS_TWILIO_FROM'),
    ],

];
