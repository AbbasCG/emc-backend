<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class TicketController extends Controller
{
    /**
     * Get system metadata for tickets (departments, units, statuses, priorities).
     */
    public function meta()
    {
        $departments = Department::with('sections')->where('is_active', true)->get();
        
        $techDepartment = Department::where('slug', 'tech-support')
            ->orWhere('name_ar', 'LIKE', '%التقنية%')
            ->first();

        $techUnits = [];
        if ($techDepartment) {
            $techUnits = DepartmentalUnit::where('department_id', $techDepartment->id)
                ->where('is_active', true)
                ->get();
        }

        $users = User::select('id', 'name', 'email')->get();

        return response()->json([
            'success' => true,
            'departments' => $departments,
            'tech_units' => $techUnits,
            'users' => $users,
            'categories' => [
                ['id' => 'OLD_ISSUE', 'label_ar' => 'مشكلة قائمة / قديمة', 'label_en' => 'Existing / Past Issue'],
                ['id' => 'NEW_SUGGESTION', 'label_ar' => 'مقترح جديد / تطويري', 'label_en' => 'New Feature / Improvement Suggestion'],
            ],
            'priorities' => [
                ['id' => 'LOW', 'label_ar' => 'منخفضة', 'color' => 'gray'],
                ['id' => 'MEDIUM', 'label_ar' => 'متوسطة', 'color' => 'blue'],
                ['id' => 'HIGH', 'label_ar' => 'عالية', 'color' => 'amber'],
                ['id' => 'CRITICAL', 'label_ar' => 'حرجة / طارئة', 'color' => 'red'],
            ],
            'statuses' => [
                'PENDING_APPROVAL' => ['label_ar' => 'معلقة (بانتظار الاعتماد)', 'color' => 'yellow'],
                'ASSIGNED' => ['label_ar' => 'معتمدة ومحالة', 'color' => 'blue'],
                'IN_PROGRESS' => ['label_ar' => 'قيد المعالجة', 'color' => 'purple'],
                'RESOLVED' => ['label_ar' => 'تم الحل والإغلاق', 'color' => 'green'],
                'UNRESOLVED' => ['label_ar' => 'تعذر الحل', 'color' => 'orange'],
                'REJECTED_BY_ADMIN' => ['label_ar' => 'مرفوضة من الإدارة', 'color' => 'red'],
                'REJECTED_BY_ASSIGNEE' => ['label_ar' => 'مرفوضة من المكلف', 'color' => 'rose'],
            ]
        ]);
    }

    /**
     * Get filterable tickets list with pagination.
     */
    public function index(Request $request)
    {
        $query = Ticket::with(['department', 'unit', 'creator', 'approver', 'assignee', 'attachments']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->unit_id);
        }

        if ($request->filled('ticket_category')) {
            $query->where('ticket_category', $request->ticket_category);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('assigned_to_id')) {
            $query->where('assigned_to_id', $request->assigned_to_id);
        }

        if ($request->filled('created_by_id')) {
            $query->where('created_by_id', $request->created_by_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'LIKE', "%{$search}%")
                  ->orWhere('title', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%")
                  ->orWhere('created_by_name', 'LIKE', "%{$search}%");
            });
        }

        if ($request->boolean('delayed_only')) {
            $query->whereNotIn('status', ['RESOLVED', 'REJECTED_BY_ADMIN', 'UNRESOLVED'])
                  ->whereNotNull('expected_resolution_time')
                  ->where('expected_resolution_time', '<', now());
        }

        $tickets = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $tickets,
        ]);
    }

    /**
     * Submit a new ticket with optional file attachments.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'ticket_category' => 'required|in:OLD_ISSUE,NEW_SUGGESTION',
            'priority' => 'nullable|in:LOW,MEDIUM,HIGH,CRITICAL',
            'department_id' => 'required|exists:departments,id',
            'created_by_name' => 'required|string|max:150',
            'created_by_email' => 'nullable|email|max:150',
            'attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,avi,pdf,doc,docx|max:51200', // max 50MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات التذكرة غير مكتملة أو تحتوي على أخطاء',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Generate ticket number: EMC-TCK-YYYY-XXX
            $year = date('Y');
            $count = Ticket::whereYear('created_at', $year)->withTrashed()->count() + 1;
            $ticketNumber = sprintf('EMC-TCK-%s-%03d', $year, $count);

            $ticket = Ticket::create([
                'ticket_number' => $ticketNumber,
                'title' => $request->title,
                'description' => $request->description,
                'ticket_category' => $request->ticket_category,
                'priority' => $request->priority ?? 'MEDIUM',
                'department_id' => $request->department_id,
                'created_by_id' => auth()->id() ?? null,
                'created_by_name' => $request->created_by_name,
                'created_by_email' => $request->created_by_email,
                'status' => 'PENDING_APPROVAL',
            ]);

            // Handle file attachments
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $mime = $file->getMimeType();
                    $fileType = 'DOCUMENT';
                    if (str_contains($mime, 'image')) {
                        $fileType = 'IMAGE';
                    } elseif (str_contains($mime, 'video')) {
                        $fileType = 'VIDEO';
                    }

                    $path = $file->store('tickets/attachments', 'public');
                    $fileUrl = Storage::url($path);

                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'file_url' => $fileUrl,
                        'file_name' => $file->getClientOriginalName(),
                        'file_type' => $fileType,
                        'attachment_context' => 'INITIAL_SUBMISSION',
                        'uploaded_by' => auth()->id(),
                    ]);
                }
            }

            // Create activity log
            TicketActivityLog::create([
                'ticket_id' => $ticket->id,
                'action' => 'SUBMITTED',
                'previous_state' => null,
                'new_state' => 'PENDING_APPROVAL',
                'performed_by' => auth()->id(),
                'performed_by_name' => $request->created_by_name,
                'details' => 'تم إنشاء التذكرة بنجاح وتوجيهها للإدارة المعنية',
                'logged_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'تم تقديم التذكرة بنجاح وتندرج الآن تحت بند معلقة بانتظار اعتماد الإدارة',
                'data' => $ticket->load(['department', 'attachments', 'activityLogs']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء حفظ التذكرة: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View single ticket details.
     */
    public function show($id)
    {
        $ticket = Ticket::with([
            'department',
            'unit',
            'creator',
            'approver',
            'assignee',
            'attachments',
            'activityLogs'
        ])->where('id', $id)->orWhere('ticket_number', $id)->first();

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'message' => 'التذكرة غير موجودة',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $ticket,
        ]);
    }

    /**
     * Admin/Manager Action: Approve ticket, assign unit & user, set SLA deadline.
     */
    public function approve(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'unit_id' => 'nullable|exists:departmental_units,id',
            'assigned_to_id' => 'nullable|exists:users,id',
            'sla_hours' => 'required|integer|min:1|max:720', // e.g. 24, 48, 72 hours
            'internal_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'الرجاء تحديد المهلة الزمنية للـ SLA بشكل صحيح',
                'errors' => $validator->errors(),
            ], 422);
        }

        $prevState = $ticket->status;
        $expectedSlaTime = now()->addHours((int) $request->sla_hours);

        $ticket->update([
            'unit_id' => $request->unit_id,
            'assigned_to_id' => $request->assigned_to_id,
            'approved_by_id' => auth()->id(),
            'status' => 'ASSIGNED',
            'expected_resolution_time' => $expectedSlaTime,
            'internal_notes' => $request->internal_notes,
        ]);

        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'action' => 'APPROVED_AND_ASSIGNED',
            'previous_state' => $prevState,
            'new_state' => 'ASSIGNED',
            'performed_by' => auth()->id(),
            'performed_by_name' => auth()->user()?->name ?? 'مدير الإدارة',
            'details' => sprintf('تم قبول واعتماد التذكرة وتحديد المهلة (SLA) حتى %s', $expectedSlaTime->format('Y-m-d H:i')),
            'logged_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم قبول واعتماد التذكرة وإحالتها بنجاح',
            'data' => $ticket->fresh(['department', 'unit', 'assignee', 'approver', 'activityLogs']),
        ]);
    }

    /**
     * Admin/Manager Action: Reject ticket with MANDATORY reason.
     */
    public function rejectByAdmin(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'admin_rejection_reason' => 'required|string|min:5',
        ], [
            'admin_rejection_reason.required' => 'حقل سبب الرفض إجباري عند رفض التذكرة من قبل الإدارة',
            'admin_rejection_reason.min' => 'يجب كتابة سبب الرفض بتفصيل لا يقل عن 5 حروف',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'سبب الرفض إجباري قبل الإغلاق',
                'errors' => $validator->errors(),
            ], 422);
        }

        $prevState = $ticket->status;

        $ticket->update([
            'approved_by_id' => auth()->id(),
            'status' => 'REJECTED_BY_ADMIN',
            'admin_rejection_reason' => $request->admin_rejection_reason,
        ]);

        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'action' => 'REJECTED_BY_ADMIN',
            'previous_state' => $prevState,
            'new_state' => 'REJECTED_BY_ADMIN',
            'performed_by' => auth()->id(),
            'performed_by_name' => auth()->user()?->name ?? 'مدير الإدارة',
            'details' => 'سبب الرفض: ' . $request->admin_rejection_reason,
            'logged_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم رفض التذكرة وتدوين سبب الرفض الإداري بنجاح',
            'data' => $ticket->fresh(['activityLogs']),
        ]);
    }

    /**
     * Assignee Action: Accept task assignment -> Status IN_PROGRESS.
     */
    public function acceptTask(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);

        $prevState = $ticket->status;

        $ticket->update([
            'status' => 'IN_PROGRESS',
            'accepted_at' => now(),
        ]);

        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'action' => 'ACCEPTED_BY_ASSIGNEE',
            'previous_state' => $prevState,
            'new_state' => 'IN_PROGRESS',
            'performed_by' => auth()->id(),
            'performed_by_name' => auth()->user()?->name ?? 'العضو المكلف',
            'details' => 'تم قبول استلام المهمة وبدء عداد التنفيذ بالـ SLA',
            'logged_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم قبول استلام المهمة وبدء العمل عليها',
            'data' => $ticket->fresh(['activityLogs']),
        ]);
    }

    /**
     * Assignee Action: Reject task assignment with MANDATORY reason.
     */
    public function rejectTask(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'assignee_rejection_reason' => 'required|string|min:5',
        ], [
            'assignee_rejection_reason.required' => 'يلزم تدوين مبررات اعتذار/رفض استلام المهمة',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'سبب الرفض إجباري عند الاعتذار عن المهمة',
                'errors' => $validator->errors(),
            ], 422);
        }

        $prevState = $ticket->status;

        $ticket->update([
            'status' => 'REJECTED_BY_ASSIGNEE',
            'assignee_rejection_reason' => $request->assignee_rejection_reason,
        ]);

        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'action' => 'REJECTED_BY_ASSIGNEE',
            'previous_state' => $prevState,
            'new_state' => 'REJECTED_BY_ASSIGNEE',
            'performed_by' => auth()->id(),
            'performed_by_name' => auth()->user()?->name ?? 'العضو المكلف',
            'details' => 'مبررات الاعتذار: ' . $request->assignee_rejection_reason,
            'logged_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل اعتذار الاستلام وتصعيد التذكرة للإدارة للتوجيه البديل',
            'data' => $ticket->fresh(['activityLogs']),
        ]);
    }

    /**
     * Assignee Action: Complete Task with strict validation rules.
     * - RESOLVED: REQUIRES resolution_summary + optional proof files
     * - UNRESOLVED: REQUIRES unresolved_reason
     */
    public function completeTask(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'resolution_type' => 'required|in:RESOLVED,UNRESOLVED',
            'resolution_summary' => 'required_if:resolution_type,RESOLVED|nullable|string|min:5',
            'unresolved_reason' => 'required_if:resolution_type,UNRESOLVED|nullable|string|min:5',
            'proof_files.*' => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,pdf|max:51200',
        ], [
            'resolution_summary.required_if' => 'حقل (ماذا تم لحل المشكلة) إجباري عند اختيار "تم الحل"',
            'unresolved_reason.required_if' => 'حقل (أسباب عدم التمكن من الحل والعوائق) إجباري عند اختيار "تعذر الحل"',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'الرجاء استكمال البيانات والتبريرات الإجبارية',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $prevState = $ticket->status;
            $newStatus = $request->resolution_type;

            $updateData = [
                'status' => $newStatus,
            ];

            if ($newStatus === 'RESOLVED') {
                $updateData['resolved_at'] = now();
                $updateData['resolution_summary'] = $request->resolution_summary;
            } else {
                $updateData['unresolved_reason'] = $request->unresolved_reason;
            }

            if ($request->filled('internal_notes')) {
                $updateData['internal_notes'] = $request->internal_notes;
            }

            $ticket->update($updateData);

            // Handle resolution proof attachments
            if ($request->hasFile('proof_files')) {
                foreach ($request->file('proof_files') as $file) {
                    $mime = $file->getMimeType();
                    $fileType = 'DOCUMENT';
                    if (str_contains($mime, 'image')) {
                        $fileType = 'IMAGE';
                    } elseif (str_contains($mime, 'video')) {
                        $fileType = 'VIDEO';
                    }

                    $path = $file->store('tickets/proofs', 'public');
                    $fileUrl = Storage::url($path);

                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'file_url' => $fileUrl,
                        'file_name' => $file->getClientOriginalName(),
                        'file_type' => $fileType,
                        'attachment_context' => 'RESOLUTION_PROOF',
                        'uploaded_by' => auth()->id(),
                    ]);
                }
            }

            // Log activity
            TicketActivityLog::create([
                'ticket_id' => $ticket->id,
                'action' => $newStatus === 'RESOLVED' ? 'RESOLVED' : 'MARKED_UNRESOLVED',
                'previous_state' => $prevState,
                'new_state' => $newStatus,
                'performed_by' => auth()->id(),
                'performed_by_name' => auth()->user()?->name ?? 'العضو المكلف',
                'details' => $newStatus === 'RESOLVED' 
                    ? 'تم حل المشكلة وتدوين الإجراءات المتخذة' 
                    : 'تعذر الحل: ' . $request->unresolved_reason,
                'logged_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $newStatus === 'RESOLVED' ? 'تم إنهاء التذكرة كـ (تم الحل) بنجاح' : 'تم تدوين حالة تعذر الحل وتصعيد التقرير للقيادة',
                'data' => $ticket->fresh(['department', 'unit', 'attachments', 'activityLogs']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء حفظ حالة الإغلاق: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add a comment / follow-up note to a ticket (visible to all parties).
     */
    public function addComment(Request $request, $id)
    {
        $ticket = Ticket::where('id', $id)->orWhere('ticket_number', $id)->firstOrFail();

        $request->validate([
            'comment'      => 'required|string|max:2000',
            'is_internal'  => 'nullable|boolean',
        ]);

        TicketActivityLog::create([
            'ticket_id'         => $ticket->id,
            'action'            => $request->boolean('is_internal') ? 'INTERNAL_NOTE' : 'COMMENT',
            'previous_state'    => $ticket->status,
            'new_state'         => $ticket->status,
            'performed_by'      => auth()->id(),
            'performed_by_name' => auth()->user()?->name ?? 'مستخدم',
            'details'           => $request->comment,
            'logged_at'         => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة التعقيب بنجاح',
        ]);
    }

    /**
     * Reassign ticket to a different assignee (Leadership only).
     */
    public function reassign(Request $request, $id)
    {
        $ticket = Ticket::where('id', $id)->orWhere('ticket_number', $id)->firstOrFail();

        $request->validate([
            'assigned_to_id' => 'required|exists:users,id',
            'unit_id'        => 'nullable|exists:departmental_units,id',
            'reason'         => 'nullable|string|max:500',
        ]);

        $ticket->update([
            'assigned_to_id' => $request->assigned_to_id,
            'unit_id'        => $request->unit_id ?? $ticket->unit_id,
            'status'         => 'ASSIGNED',
            'accepted_at'    => null,
        ]);

        TicketActivityLog::create([
            'ticket_id'         => $ticket->id,
            'action'            => 'REASSIGNED',
            'previous_state'    => $ticket->status,
            'new_state'         => 'ASSIGNED',
            'performed_by'      => auth()->id(),
            'performed_by_name' => auth()->user()?->name ?? 'القيادة',
            'details'           => 'تمت إعادة التوجيه وتغيير المكلف' . ($request->reason ? ': ' . $request->reason : ''),
            'logged_at'         => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تمت إعادة توجيه التذكرة وتغيير المكلف بنجاح',
            'data'    => $ticket->fresh(['department', 'unit', 'assignee']),
        ]);
    }
}
