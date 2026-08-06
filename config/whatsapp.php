<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "meta", "twilio", "log"
    |
    | "log"  — No real messages sent; writes to Laravel log. Safe for local dev.
    | "meta" — Meta WhatsApp Cloud API (requires WHATSAPP_META_TOKEN etc.)
    | "twilio" — Twilio WhatsApp Sandbox / Production.
    |
    */
    'provider' => env('WHATSAPP_PROVIDER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Meta WhatsApp Cloud API
    |--------------------------------------------------------------------------
    */
    'meta' => [
        'token'           => env('WHATSAPP_META_TOKEN'),
        'phone_number_id' => env('WHATSAPP_META_PHONE_NUMBER_ID'),
        'api_version'     => env('WHATSAPP_META_API_VERSION', 'v19.0'),
        'base_url'        => 'https://graph.facebook.com',
        'verify_token'    => env('WHATSAPP_META_VERIFY_TOKEN'),  // for webhook verification
    ],

    /*
    |--------------------------------------------------------------------------
    | Twilio WhatsApp
    |--------------------------------------------------------------------------
    */
    'twilio' => [
        'sid'   => env('WHATSAPP_TWILIO_SID'),
        'token' => env('WHATSAPP_TWILIO_TOKEN'),
        'from'  => env('WHATSAPP_TWILIO_FROM'),  // whatsapp:+14155238886
    ],

];
