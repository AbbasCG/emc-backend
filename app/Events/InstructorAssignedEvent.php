<?php

namespace App\Events;

use App\Models\Course;
use App\Models\Instructor;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InstructorAssignedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Course $course,
        public readonly Instructor $instructor,
        public readonly int $assignedByUserId,
    ) {}
}
