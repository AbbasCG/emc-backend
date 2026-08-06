<?php

namespace App\Events;

use App\Models\Course;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CourseCreatedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Course $course,
        public readonly int $createdByUserId,
    ) {}
}
