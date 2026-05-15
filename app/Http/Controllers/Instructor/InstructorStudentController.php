<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\RegistrationResource;
use App\Models\Course;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InstructorStudentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $profile = $user->instructorProfile;

        $courseIds = $profile
            ? Course::query()->where('instructor_id', $profile->id)->pluck('id')
            : collect();

        $query = Registration::query()
            ->with(['course', 'payments'])
            ->whereIn('course_id', $courseIds)
            ->where('status', 'Registered')
            ->orderByDesc('created_at');

        if ($request->filled('q')) {
            $q = (string) $request->query('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('full_name', 'like', '%' . $q . '%')
                    ->orWhere('email', 'like', '%' . $q . '%');
            });
        }

        $perPage = min((int) $request->query('per_page', 30), 100);

        return RegistrationResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Students retrieved successfully']);
    }
}
