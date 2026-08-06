<?php

namespace App\Events\Certificate;

use App\Models\Certificate;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CertificatePdfFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Certificate $certificate,
        public readonly \Throwable  $exception,
        public readonly int         $attempt = 1,
    ) {}
}
