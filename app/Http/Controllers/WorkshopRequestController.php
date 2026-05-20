<?php

namespace App\Http\Controllers;

use App\Events\WorkshopRequestSubmittedEvent;
use App\Http\Requests\StoreWorkshopRequestRequest;
use App\Http\Resources\WorkshopRequestResource;
use App\Mail\WorkshopRequestConfirmationMail;
use App\Mail\WorkshopRequestSubmittedMail;
use App\Models\WorkshopRequest;
use App\Services\AuditLogService;
use App\Services\WorkshopAnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Mail;
use Throwable;

class WorkshopRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', 15), 100);

        $query = WorkshopRequest::query()->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $workshopRequests = $query->paginate($perPage);

        return WorkshopRequestResource::collection($workshopRequests)
            ->additional([
                'success' => true,
                'message' => 'Workshop requests retrieved successfully',
            ]);
    }

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

            event(new WorkshopRequestSubmittedEvent($workshopRequest));
            $this->sendEmails($workshopRequest);

            return (new WorkshopRequestResource($workshopRequest))
                ->additional([
                    'success' => true,
                    'message' => 'تم استقبال طلب البرنامج التدريبي بنجاح',
                ])
                ->response()
                ->setStatusCode(201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء معالجة الطلب',
            ], 500);
        }
    }

    public function show(WorkshopRequest $workshopRequest): JsonResponse
    {
        return (new WorkshopRequestResource($workshopRequest))
            ->additional(['success' => true])
            ->response();
    }

    public function update(Request $request, WorkshopRequest $workshopRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => 'sometimes|string|max:50',
            'generated_announcement_text' => 'sometimes|nullable|string',
        ]);

        $oldStatus = $workshopRequest->status;
        $oldSnap   = ['status' => $oldStatus, 'program_name' => $workshopRequest->program_name];

        $workshopRequest->update($data);
        $fresh = $workshopRequest->fresh();

        $newStatus = $fresh->status;
        $action    = match ($newStatus) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            default    => isset($data['status']) && $newStatus !== $oldStatus ? 'status_changed' : 'updated',
        };

        AuditLogService::log($action, 'WorkshopRequest', $fresh->id,
            $fresh->program_name,
            $oldSnap,
            ['status' => $newStatus, 'program_name' => $fresh->program_name],
            null, $request);

        return (new WorkshopRequestResource($fresh))
            ->additional(['success' => true, 'message' => 'Workshop request updated successfully'])
            ->response();
    }

    public function destroy(WorkshopRequest $workshopRequest): JsonResponse
    {
        $snap  = ['status' => $workshopRequest->status, 'program_name' => $workshopRequest->program_name];
        $label = $workshopRequest->program_name;
        $id    = $workshopRequest->id;

        $workshopRequest->delete();

        AuditLogService::log('deleted', 'WorkshopRequest', $id, $label, $snap, null, null, request());

        return response()->json(['success' => true, 'message' => 'Workshop request deleted successfully']);
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
