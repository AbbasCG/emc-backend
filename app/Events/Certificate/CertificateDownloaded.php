<?php

namespace App\Events\Certificate;

use App\Models\Certificate;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CertificateDownloaded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Certificate $certificate,
        public readonly ?User       $by = null,
    ) {}
}
