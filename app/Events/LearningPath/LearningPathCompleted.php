<?php

namespace App\Events\LearningPath;

use App\Models\LearningPath;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LearningPathCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User         $user,
        public readonly LearningPath $learningPath,
    ) {}
}
