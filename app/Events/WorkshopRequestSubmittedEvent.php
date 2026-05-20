<?php

namespace App\Events;

use App\Models\WorkshopRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkshopRequestSubmittedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkshopRequest $workshopRequest,
    ) {}
}
