<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\VolunteerRequestResource;
use App\Mail\VolunteerApplicationConfirmationMail;
use App\Models\VolunteerRequest;
use App\Services\EmailService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VolunteerRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name'           => 'required|string|max:255',
            'email'               => 'required|email|max:255',
            'phone'               => 'required|string|max:30',
            'country'             => 'required|string|max:100',
            'city'                => 'required|string|max:100',
            'gender'              => 'required|string|max:30',
            'desired_department'  => 'required|string|max:150',
            'experience_level'    => 'required|string|max:50',
            'skills'              => 'nullable|string',
            'availability'        => 'required|string|max:100',
            'motivation'          => 'required|string|min:20',
            'previous_experience' => 'nullable|string',
            'cv_file'             => 'nullable|file|mimes:pdf,doc,docx|max:5120',
            'notes'               => 'nullable|string',
            'agree_terms'         => 'accepted',
        ]);

        $cvPath = null;
        if ($request->hasFile('cv_file')) {
            $ext    = $request->file('cv_file')->getClientOriginalExtension();
            $safe   = 'cv_' . Str::uuid() . '.' . $ext;
            $cvPath = $request->file('cv_file')->storeAs('volunteer-cvs', $safe, 'private');
        }

        $volunteerRequest = VolunteerRequest::create([
            'full_name'           => $data['full_name'],
            'email'               => $data['email'],
            'phone'               => $data['phone'],
            'country'             => $data['country'],
            'city'                => $data['city'],
            'gender'              => $data['gender'],
            'desired_department'  => $data['desired_department'],
            'experience_level'    => $data['experience_level'],
            'skills'              => $data['skills'] ?? null,
            'availability'        => $data['availability'],
            'motivation'          => $data['motivation'],
            'previous_experience' => $data['previous_experience'] ?? null,
            'cv_file_path'        => $cvPath,
            'notes'               => $data['notes'] ?? null,
            'status'              => 'pending',
        ]);

        // Confirmation to applicant
        EmailService::dispatch(
            'volunteer_confirmation',
            $volunteerRequest->email,
            $volunteerRequest->full_name,
            null,
            new VolunteerApplicationConfirmationMail(
                $volunteerRequest->full_name,
                $volunteerRequest->desired_department,
                now()->format('Y-m-d H:i'),
            ),
            'تم استلام طلب التطوع — منصة EMC',
        );

        // In-app + email notification to admins/HR
        NotificationService::volunteerRequestSubmitted(
            $volunteerRequest->id,
            $volunteerRequest->full_name,
            $volunteerRequest->desired_department,
            $volunteerRequest,
        );

        return response()->json([
            'success' => true,
            'message' => 'Volunteer request submitted successfully.',
            'data'    => new VolunteerRequestResource($volunteerRequest),
        ], 201);
    }
}
