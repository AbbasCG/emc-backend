<?php

namespace App\Http\Controllers\Public;

use App\Rules\PdfCvFile;
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
            // Applications to a specific opportunity go through POST /volunteer-opportunities/{id}/applications, which
            // enforces the opportunity's lifecycle (draft/closed/expired), one application per email and the stricter answers.
            // Accepting the id here would bypass all of that, so this general endpoint refuses it.
            'opportunity_id'      => 'prohibited',
            'full_name'           => 'required|string|max:255',
            'email'               => 'required|email|max:255',
            'phone'               => 'required|string|max:30',
            'country'             => 'required|string|max:100',
            'city'                => 'required|string|max:100',
            'gender'              => 'nullable|string|max:30',
            'birth_date'          => 'nullable|date',
            'nationality'         => 'nullable|string|max:100',

            // المستوى الثاني
            'education_level'     => 'nullable|string|max:100',
            'current_status'      => 'nullable|string|max:100',
            'job_title'           => 'nullable|string|max:150',
            'specialization'      => 'nullable|string|max:150',
            'volunteer_days_availability' => 'nullable|string|max:100',
            'volunteer_days'      => 'nullable',
            'preferred_time_slot' => 'nullable|string|max:100',
            'daily_hours'         => 'nullable|string|max:100',
            'in_person_capable'   => 'nullable|string|max:50',
            'remote_capable'      => 'nullable|string|max:50',

            // المستوى الثالث
            'has_previous_volunteering'  => 'nullable|boolean',
            'volunteering_organizations' => 'nullable|string',
            'volunteering_duration'      => 'nullable|string|max:100',
            'past_volunteer_tasks'       => 'nullable',
            'personal_skills'            => 'nullable',
            'skill_level_rating'         => 'nullable|integer|min:1|max:5',
            'technical_tools'            => 'nullable',
            'value_add_contributions'    => 'nullable|string',
            'portfolio_link_status'      => 'nullable|string|max:100',
            'portfolio_url'              => 'nullable|string|max:255',

            // المستوى الرابع
            'motivation_ratings'         => 'nullable',
            'commitment_ratings'         => 'nullable',
            'situational_responses'      => 'nullable',
            'expected_duration'          => 'nullable|string|max:100',
            'commitment_three_months'    => 'nullable|string|max:50',

            // المستوى الخامس
            'reasons_for_applying'       => 'nullable',
            'expected_gains'             => 'nullable',
            'secondary_departments'      => 'nullable',
            'primary_value_department'   => 'nullable|string|max:150',
            'department_skills'          => 'nullable',
            'preferred_work_environment' => 'nullable|string|max:100',
            'quit_reasons'               => 'nullable',
            'suggestions'                => 'nullable|string',
            'assessment_data'            => 'nullable',

            // الحقول العامة
            'desired_department'  => 'required|string|max:150',
            'experience_level'    => 'nullable|string|max:50',
            'skills'              => 'nullable|string',
            'availability'        => 'nullable|string|max:100',
            'motivation'          => 'nullable|string',
            'previous_experience' => 'nullable|string',
            'cv_file'             => ['nullable', 'file', 'max:5120', new PdfCvFile],
            'notes'               => 'nullable|string',
            'agree_terms'         => 'accepted',
        ], [
            'opportunity_id.prohibited' => 'للتقديم على فرصة تطوعية محددة استخدم صفحة الفرصة نفسها.',
        ]);

        $cvPath = null;
        if ($request->hasFile('cv_file')) {
            $safe   = 'cv_' . Str::uuid() . '.pdf'; // validated as a real PDF; the name never comes from the client
            $cvPath = $request->file('cv_file')->storeAs('volunteer-cvs', $safe, 'private');
        }

        $parseJsonOrArray = function ($value) {
            if (is_array($value)) return $value;
            if (is_string($value) && trim($value) !== '') {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) return $decoded;
            }
            return null;
        };

        $volunteerRequest = VolunteerRequest::create([
            'full_name'           => $data['full_name'],
            'email'               => $data['email'],
            'phone'               => $data['phone'],
            'country'             => $data['country'],
            'city'                => $data['city'],
            'gender'              => $data['gender'] ?? 'unspecified',
            'birth_date'          => $data['birth_date'] ?? null,
            'nationality'         => $data['nationality'] ?? null,

            // المستوى الثاني
            'education_level'     => $data['education_level'] ?? null,
            'current_status'      => $data['current_status'] ?? null,
            'job_title'           => $data['job_title'] ?? null,
            'specialization'      => $data['specialization'] ?? null,
            'volunteer_days_availability' => $data['volunteer_days_availability'] ?? null,
            'volunteer_days'      => $parseJsonOrArray($data['volunteer_days'] ?? null),
            'preferred_time_slot' => $data['preferred_time_slot'] ?? null,
            'daily_hours'         => $data['daily_hours'] ?? null,
            'in_person_capable'   => $data['in_person_capable'] ?? null,
            'remote_capable'      => $data['remote_capable'] ?? null,

            // المستوى الثالث
            'has_previous_volunteering'  => isset($data['has_previous_volunteering']) ? filter_var($data['has_previous_volunteering'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
            'volunteering_organizations' => $data['volunteering_organizations'] ?? null,
            'volunteering_duration'      => $data['volunteering_duration'] ?? null,
            'past_volunteer_tasks'       => $parseJsonOrArray($data['past_volunteer_tasks'] ?? null),
            'personal_skills'            => $parseJsonOrArray($data['personal_skills'] ?? null),
            'skill_level_rating'         => isset($data['skill_level_rating']) ? (int) $data['skill_level_rating'] : null,
            'technical_tools'            => $parseJsonOrArray($data['technical_tools'] ?? null),
            'value_add_contributions'    => $data['value_add_contributions'] ?? null,
            'portfolio_link_status'      => $data['portfolio_link_status'] ?? null,
            'portfolio_url'              => $data['portfolio_url'] ?? null,

            // المستوى الرابع
            'motivation_ratings'         => $parseJsonOrArray($data['motivation_ratings'] ?? null),
            'commitment_ratings'         => $parseJsonOrArray($data['commitment_ratings'] ?? null),
            'situational_responses'      => $parseJsonOrArray($data['situational_responses'] ?? null),
            'expected_duration'          => $data['expected_duration'] ?? null,
            'commitment_three_months'    => $data['commitment_three_months'] ?? null,

            // المستوى الخامس
            'reasons_for_applying'       => $parseJsonOrArray($data['reasons_for_applying'] ?? null),
            'expected_gains'             => $parseJsonOrArray($data['expected_gains'] ?? null),
            'secondary_departments'      => $parseJsonOrArray($data['secondary_departments'] ?? null),
            'primary_value_department'   => $data['primary_value_department'] ?? null,
            'department_skills'          => $parseJsonOrArray($data['department_skills'] ?? null),
            'preferred_work_environment' => $data['preferred_work_environment'] ?? null,
            'quit_reasons'               => $parseJsonOrArray($data['quit_reasons'] ?? null),
            'suggestions'                => $data['suggestions'] ?? null,
            'assessment_data'            => $parseJsonOrArray($data['assessment_data'] ?? null),

            // الحقول العامة السابقة
            'desired_department'  => $data['desired_department'],
            // A question the applicant did not answer is stored as NULL, never as an invented answer, and an answer is
            // never copied into a different question (education level is not experience level, what a person can
            // contribute is not why they volunteer). `gender` keeps its approved 'unspecified' value above.
            'experience_level'    => $data['experience_level'] ?? null,
            'skills'              => $data['skills'] ?? (is_array($data['personal_skills'] ?? null) ? implode(', ', $data['personal_skills']) : null),
            'availability'        => $data['availability'] ?? null,
            'motivation'          => $data['motivation'] ?? null,
            'previous_experience' => $data['previous_experience'] ?? ($data['volunteering_organizations'] ?? null),
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
