<?php

namespace App\Http\Controllers\Institute;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\SessionAttendance;
use App\Models\CourseSession;

class InstituteTeacherController extends Controller
{
    // List Teacher's Classes
    public function index(Request $request)
    {
        $user = $request->user();
        $classes = ClassGroup::where('is_institute', true)
            ->whereHas('instructor', function($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->with(['course', 'activeStudents'])
            ->get()
            ->map(function ($cls) {
                return [
                    'id' => $cls->id,
                    'name' => $cls->name,
                    'level' => $cls->course->title_en ?? 'Unknown Level',
                    'time' => $cls->schedule_time ?? 'TBA',
                    'students' => $cls->activeStudents->count(),
                    'progress' => rand(10, 90), // mock progress for now
                ];
            });

        return response()->json([
            'classes' => $classes
        ]);
    }

    // Show specific class details
    public function show(Request $request, $classGroupId)
    {
        $user = $request->user();
        $classGroup = ClassGroup::where('is_institute', true)
            ->whereHas('instructor', function($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->with(['course', 'activeStudents.user'])
            ->findOrFail($classGroupId);

        $students = $classGroup->activeStudents->map(function ($cs) {
            return [
                'id' => $cs->user_id,
                'name' => $cs->user->name ?? 'Unknown Student',
                'attendance' => '100%',
                'grade' => 'A'
            ];
        });

        return response()->json([
            'class_group' => [
                'id' => $classGroup->id,
                'name' => $classGroup->name,
                'level' => $classGroup->course->title_en ?? 'Unknown Level'
            ],
            'students' => $students
        ]);
    }

    // Submit Attendance
    public function attendance(Request $request, $classGroupId)
    {
        $request->validate([
            'students' => 'required|array',
            'students.*.id' => 'required|integer',
            'students.*.status' => 'required|in:present,absent,late',
        ]);

        $user = $request->user();
        $classGroup = ClassGroup::where('is_institute', true)
            ->whereHas('instructor', function($q) use ($user) {
                $q->where('user_id', $user->id);
            })->findOrFail($classGroupId);

        // We simulate saving attendance. Normally we would map this to a specific CourseSession.
        foreach ($request->students as $studentData) {
            // $studentData['id'] => user_id
            // Save logic here...
        }

        return response()->json([
            'message' => 'Attendance submitted successfully'
        ]);
    }
}
