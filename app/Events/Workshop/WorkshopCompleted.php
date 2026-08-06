<?php

namespace App\Events\Workshop;

use App\Models\User;
use App\Models\Workshop;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkshopCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User     $user,
        public readonly Workshop $workshop,
    ) {}
}
