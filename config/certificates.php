<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | The queue name used for all certificate PDF generation jobs.
    */
    'queue' => env('CERTIFICATE_QUEUE', 'certificates'),

    /*
    |--------------------------------------------------------------------------
    | Retry limits
    |--------------------------------------------------------------------------
    | max_retry_count  — how many times certificates:retry-failed may re-queue
    |                    a single certificate before giving up permanently.
    | max_pdf_attempts — the Laravel job attempt limit for GenerateCertificatePdfJob.
    | pdf_timeout      — seconds before a PDF generation job is killed by the
    |                    queue worker. Should be ≥ the slowest expected render.
    |                    uniqueFor on the job is derived: pdf_timeout × max_pdf_attempts
    |                    + backoff buffer (≈ 1800s). Adjust both together.
    */
    'max_retry_count'  => (int) env('CERTIFICATE_MAX_RETRY_COUNT', 5),
    'max_pdf_attempts' => (int) env('CERTIFICATE_MAX_PDF_ATTEMPTS', 3),
    'pdf_timeout'      => (int) env('CERTIFICATE_PDF_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL
    |--------------------------------------------------------------------------
    | How long (in seconds) the resolved default template ID and template model
    | are kept in cache. Flushed on any template mutation.
    */
    'template_cache_ttl' => (int) env('CERTIFICATE_TEMPLATE_CACHE_TTL', 600),

    /*
    |--------------------------------------------------------------------------
    | Batch limits
    |--------------------------------------------------------------------------
    | Maximum number of recipients allowed per batch issuance request.
    */
    'max_batch_recipients' => (int) env('CERTIFICATE_MAX_BATCH_RECIPIENTS', 500),

    /*
    |--------------------------------------------------------------------------
    | Stuck-generation threshold (minutes)
    |--------------------------------------------------------------------------
    | Certificates in pending_generation for longer than this are flagged by
    | the integrity audit as potentially stuck.
    */
    'stuck_generation_minutes' => (int) env('CERTIFICATE_STUCK_GENERATION_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Stuck-batch threshold (hours)
    |--------------------------------------------------------------------------
    | Batches in processing status for longer than this are flagged as stuck.
    */
    'stuck_batch_hours' => (int) env('CERTIFICATE_STUCK_BATCH_HOURS', 2),

];
