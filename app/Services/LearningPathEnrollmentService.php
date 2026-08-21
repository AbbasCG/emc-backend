<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\Order;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LearningPathEnrollmentService
{
    public function enrollFree(User $user, LearningPath $path): LearningPathEnrollment
    {
        return DB::transaction(function () use ($user, $path) {
            $enrollment = LearningPathEnrollment::query()
                ->where('learning_path_id', $path->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $enrollment) {
                $enrollment = LearningPathEnrollment::create([
                    'learning_path_id' => $path->id,
                    'user_id' => $user->id,
                    'status' => 'active',
                    'enrolled_at' => now(),
                ]);
            } else {
                $enrollment->update([
                    'order_id' => null,
                    'status' => 'active',
                    'enrolled_at' => $enrollment->enrolled_at ?? now(),
                ]);
            }

            $this->provisionCourseAccess($user, $path);

            return $enrollment->fresh();
        });
    }

    public function createPendingPayment(User $user, LearningPath $path, Order $order): LearningPathEnrollment
    {
        return DB::transaction(function () use ($user, $path, $order) {
            $enrollment = LearningPathEnrollment::query()
                ->where('learning_path_id', $path->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $enrollment) {
                return LearningPathEnrollment::create([
                    'learning_path_id' => $path->id,
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'status' => 'pending_payment',
                    'enrolled_at' => null,
                ]);
            }

            $enrollment->update([
                'order_id' => $order->id,
                'status' => 'pending_payment',
                'completed_at' => null,
            ]);

            return $enrollment->fresh();
        });
    }

    public function activate(LearningPathEnrollment $enrollment): LearningPathEnrollment
    {
        return DB::transaction(function () use ($enrollment) {
            $locked = LearningPathEnrollment::with(['learningPath.courses', 'user'])
                ->lockForUpdate()
                ->findOrFail($enrollment->id);

            if ($locked->status === 'active' || $locked->status === 'completed') {
                return $locked;
            }

            $locked->update([
                'status' => 'active',
                'enrolled_at' => $locked->enrolled_at ?? now(),
            ]);

            $this->provisionCourseAccess($locked->user, $locked->learningPath);

            return $locked->fresh();
        });
    }

    private function provisionCourseAccess(User $user, LearningPath $path): void
    {
        $path->loadMissing('courses');

        foreach ($path->courses as $course) {
            $registration = Registration::withTrashed()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->first();

            $attributes = [
                'learning_path_id' => $path->id,
                'full_name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'city' => $user->city,
                'country' => $user->country,
                'status' => RegistrationStatus::REGISTERED->value,
            ];

            if ($registration) {
                if ($registration->trashed()) {
                    $registration->restore();
                }
                $registration->update($attributes);
            } else {
                $registration = Registration::create(array_merge($attributes, [
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                ]));
            }

            Order::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);

            StudentProgress::firstOrCreate(
                ['user_id' => $user->id, 'course_id' => $course->id],
                [
                    'status' => 'in_progress',
                    'progress_percentage' => 0,
                ],
            );
        }
    }
}
