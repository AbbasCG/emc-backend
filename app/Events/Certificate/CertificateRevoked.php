<?php

namespace App\Events\Certificate;

use App\Models\Certificate;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CertificateRevoked
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Certificate $certificate,
        public readonly ?string     $reason = null,
    ) {}
}
