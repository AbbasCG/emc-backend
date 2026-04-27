<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Registration;

class RegistrationController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'full_name' => 'required|string|max:255',
            'phone' => 'required|string|max:30',
            'email' => 'required|email|max:255',
            'city' => 'nullable|string|max:255',
            'gender' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096',
        ]);

        $course = \App\Models\Course::findOrFail($validated['course_id']);

        $receiptPath = null;

        if ($request->hasFile('receipt')) {
            $receiptPath = $request->file('receipt')->store('receipts', 'public');
        }

        $registration = \App\Models\Registration::create([
            'course_id' => $validated['course_id'],
            'full_name' => $validated['full_name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'],
            'city' => $validated['city'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $course->type === 'paid' ? 'Pending Payment' : 'Registered',
        ]);

        if ($course->type === 'paid') {
            \App\Models\Payment::create([
                'registration_id' => $registration->id,
                'amount' => $course->price,
                'payment_method' => 'bank_transfer',
                'receipt_url' => $receiptPath,
                'status' => 'Pending Payment',
            ]);
        }

        return response()->json([
            'message' => 'Registration created successfully',
            'data' => $registration
        ], 201);
    }
}
