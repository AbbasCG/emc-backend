<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Course;
use App\Models\Registration;
use App\Models\Payment;

class AdminController extends Controller
{
    public function dashboard()
    {
        return response()->json([
            'total_courses' => Course::count(),
            'total_registrations' => Registration::count(),
            'pending_payments' => Payment::where('status', 'Pending Payment')->count(),
            'confirmed_payments' => Payment::where('status', 'Payment Confirmed')->count(),
            'recent_registrations' => Registration::latest()->take(5)->get(),
        ]);
    }

    public function registrations(Request $request)
    {
        $query = Registration::with('course');

        if ($request->course_id) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->get());
    }

    public function updateRegistrationStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:Registered,Pending Payment,Payment Confirmed,Cancelled,Attended,No Show',
        ]);

        $registration = Registration::findOrFail($id);
        $registration->update([
            'status' => $validated['status']
        ]);

        if ($validated['status'] === 'Payment Confirmed') {
            Payment::where('registration_id', $registration->id)->update([
                'status' => 'Payment Confirmed',
                'confirmed_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Status updated successfully',
            'data' => $registration
        ]);
    }
}
