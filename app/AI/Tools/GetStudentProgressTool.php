<?php

namespace App\AI\Tools;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\StudentProgress;

class GetStudentProgressTool implements AiToolInterface
{
    public function name(): string
    {
        return 'get_student_progress';
    }

    public function description(): string
    {
        return 'Get a student\'s course registration and progress summary. Requires user_id.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer', 'description' => 'The student user ID'],
            ],
            'required' => ['user_id'],
        ];
    }

    public function execute(array $args, ?int $userId = null): mixed
    {
        $targetId = (int) ($args['user_id'] ?? $userId);

        // Students can only query their own data
        if ($userId && ! $this->callerIsAdmin($userId) && $targetId !== $userId) {
            return ['error' => 'Access denied. You can only view your own progress.'];
        }

        $registrations = Registration::query()
            ->where('user_id', $targetId)
            ->with('course:id,title')
            ->get(['id', 'course_id', 'status', 'created_at']);

        $progress = StudentProgress::query()
            ->where('user_id', $targetId)
            ->get(['course_id', 'progress_percentage', 'last_activity_at']);

        return [
            'user_id'       => $targetId,
            'registrations' => $registrations->count(),
            'active'        => $registrations->whereIn('status', RegistrationStatus::activeEnrollment())->count(),
            'courses'       => $registrations->map(fn ($r) => [
                'course'   => $r->course?->title,
                'status'   => $r->status,
                'progress' => $progress->firstWhere('course_id', $r->course_id)?->progress_percentage ?? 0,
            ]),
        ];
    }

    public function isAllowedForRole(string $role): bool
    {
        return true; // all roles; scoping handled inside execute()
    }

    private function callerIsAdmin(int $userId): bool
    {
        $user = \App\Models\User::query()->find($userId);

        return $user?->isAdmin() ?? false;
    }
}
