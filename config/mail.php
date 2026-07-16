<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    'designer_email' => env('DESIGNER_EMAIL', null),

    'support_email' => env('MAIL_SUPPORT_ADDRESS', env('SUPPORT_EMAIL', 'support@edumc.nl')),
    'support_name'  => env('MAIL_SUPPORT_NAME', 'EMC Support'),

    // Sender identity for the dedicated 'support' SMTP mailer below. Kept separate
    // from support_email/support_name (which are also used as Reply-To on non-support
    // mailers) so the From address always matches the support mailer's login exactly.
    'support_from_address' => env('SUPPORT_MAIL_FROM_ADDRESS', 'support@edumc.nl'),
    'support_from_name'    => env('SUPPORT_MAIL_FROM_NAME', 'EMC Support'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        // ── Support / Contact Form mailer ────────────────────────────────
        // Uses its own SMTP login (support@edumc.nl) so Contact Form and support
        // emails never ride the noreply account. Deliberately NOT a failover onto
        // 'smtp': failing over to the noreply login while the Envelope still claims
        // From: support@edumc.nl would trigger "553 Sender address rejected: not
        // owned by user". If these credentials are missing, callers must check
        // config('mail.mailers.support.username')/.password themselves (see
        // App\Mail\Concerns\UsesSupportSender) and fall back to the default mailer
        // WITH a matching default From address instead.
        'support' => [
            'transport'  => 'smtp',
            'scheme'     => env('SUPPORT_MAIL_SCHEME'),
            'url'        => env('SUPPORT_MAIL_URL'),
            'host'       => env('SUPPORT_MAIL_HOST', 'smtp.hostinger.com'),
            'port'       => env('SUPPORT_MAIL_PORT', 587),
            'username'   => env('SUPPORT_MAIL_USERNAME'),
            'password'   => env('SUPPORT_MAIL_PASSWORD'),
            'timeout'    => null,
            'local_domain' => env('SUPPORT_MAIL_EHLO_DOMAIN', env('MAIL_EHLO_DOMAIN', 'api.edumc.nl')),
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

    'designer_email' => env('DESIGNER_EMAIL'),

];
