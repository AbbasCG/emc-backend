<?php

namespace App\Events;

use App\Models\Registration;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StudentRegisteredEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Registration $registration,
    ) {}
}
