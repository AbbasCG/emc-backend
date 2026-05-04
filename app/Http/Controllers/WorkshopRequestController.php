<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkshopRequestRequest;
use App\Mail\WorkshopRequestConfirmationMail;
use App\Mail\WorkshopRequestSubmittedMail;
use App\Models\WorkshopRequest;
use App\Services\WorkshopAnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class WorkshopRequestController extends Controller
{
    public function store(
        StoreWorkshopRequestRequest $request,
        WorkshopAnnouncementService $announcementService
    ): JsonResponse {
        try {
            $validated = $request->validated();

            $speakerPhotoPath = null;
            if ($request->hasFile('speaker_photo')) {
                $speakerPhotoPath = $request->file('speaker_photo')
                    ->store('workshop-speakers', 'public');
            }

            $workshopRequest = WorkshopRequest::create([
                'requester_name' => $validated['requester_name'],
                'requester_email' => $validated['requester_email'],
                'requester_phone' => $validated['requester_phone'] ?? null,
                'requester_department' => $validated['requester_department'] ?? null,
                'program_name' => $validated['program_name'],
                'speaker_name' => $validated['speaker_name'],
                'speaker_job_title' => $validated['speaker_job_title'],
                'speaker_photo' => $speakerPhotoPath,
                'topics' => $validated['topics'],
                'categories' => $validated['categories'],
                'target_audience' => $validated['target_audience'],
                'proposed_date' => $validated['proposed_date'],
                'proposed_time' => $validated['proposed_time'],
                'location_types' => $validated['location_types'],
                'price_type' => $validated['price_type'],
                'price_amount' => $validated['price_amount'] ?? null,
                'generated_announcement_text' => null,
                'status' => 'new',
            ]);

            $announcementText = $announcementService->generateAnnouncement($workshopRequest);
            $workshopRequest->update([
                'generated_announcement_text' => $announcementText,
            ]);

            $this->sendEmails($workshopRequest);

            return response()->json([
                'message' => 'تم استقبال طلب البرنامج التدريبي بنجاح',
                'data' => $workshopRequest,
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'حدث خطأ أثناء معالجة الطلب',
            ], 500);
        }
    }

    protected function sendEmails(WorkshopRequest $workshopRequest): void
    {
        try {
            Mail::to($workshopRequest->requester_email)
                ->queue(new WorkshopRequestConfirmationMail($workshopRequest));
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            $designerEmail = (string) config('mail.designer_email', '');
            if ($designerEmail !== '' && $designerEmail !== 'null') {
                Mail::to($designerEmail)
                    ->queue(new WorkshopRequestSubmittedMail($workshopRequest));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
