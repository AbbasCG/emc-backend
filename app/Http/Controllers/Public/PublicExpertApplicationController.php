<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\ExpertApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PublicExpertApplicationController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'country' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'whatsapp_number' => 'required|string|max:50',
            'email' => 'required|email|max:255',
            'linkedin_url' => 'nullable|string|url|max:500',

            'primary_specialty' => 'required|string|max:255',
            'academic_qualification' => 'required|string|max:100',
            'current_employer' => 'nullable|string|max:255',
            'job_title' => 'required|string|max:255',
            'years_of_experience' => 'required|string|max:100',

            'expertise_fields' => 'required|array',
            'expertise_level' => 'required|string|max:100',
            'tools_technologies' => 'required|string',

            'contribution_roles' => 'required|array',

            // Trainer
            'trainer_fields' => 'nullable|array',
            'trainer_program_types' => 'nullable|array',
            'trainer_target_audiences' => 'nullable|array',
            'trainer_delivery_mode' => 'nullable|array',
            'trainer_has_experience' => 'nullable|boolean',
            'trainer_years_experience' => 'nullable|string|max:100',
            'trainer_previous_courses' => 'nullable|string',
            'trainer_content_readiness' => 'nullable|string|max:255',

            // Expert
            'expert_specialty_areas' => 'nullable|string',
            'expert_contribution_types' => 'nullable|array',
            'expert_achievements' => 'nullable|string',
            'expert_has_certifications' => 'nullable|boolean',
            'expert_certifications_details' => 'nullable|string',

            // Consultant
            'consultant_fields' => 'nullable|array',
            'consultant_types' => 'nullable|array',
            'consultant_previous_clients' => 'nullable|string',
            'consultant_target_clients' => 'nullable|array',

            // Events
            'events_types' => 'nullable|array',
            'events_contribution_areas' => 'nullable|array',
            'events_previous_events' => 'nullable|string',

            // Mentor
            'mentor_fields' => 'nullable|array',
            'mentor_target_audiences' => 'nullable|array',
            'mentor_session_types' => 'nullable|array',

            // Evaluator
            'evaluator_fields' => 'nullable|array',
            'evaluator_types' => 'nullable|array',

            // Participant
            'participant_project_types' => 'nullable|array',
            'participant_contribution_method' => 'nullable|string',

            // Availability
            'availability_times' => 'required|array',
            'availability_level' => 'required|string|max:100',
            'collaboration_preferences' => 'required|array',

            // About
            'bio' => 'required|string',
            'unique_value' => 'required|string',

            // Consent
            'agree_to_contact' => 'required|boolean',
            'agree_to_store_data' => 'required|boolean',
        ]);

        try {
            $application = ExpertApplication::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Expert application submitted successfully',
                'uuid' => $application->uuid,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to submit expert application: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit application. Please try again.',
            ], 500);
        }
    }
}
