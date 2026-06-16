<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendLoggedEmailJob;
use App\Mail\WelcomeMail;
use App\Models\NotificationLog;
use App\Services\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmailLogsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = NotificationLog::where('channel', 'email')
            ->with('user:id,name,email')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total'        => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $base = NotificationLog::where('channel', 'email');

        return response()->json([
            'data' => [
                'total'       => (clone $base)->count(),
                'sent_today'  => (clone $base)->where('status', 'sent')->whereDate('sent_at', today())->count(),
                'failed_today'=> (clone $base)->where('status', 'failed')->whereDate('failed_at', today())->count(),
                'pending'     => (clone $base)->where('status', 'pending')->count(),
            ],
        ]);
    }

    public function retry(int $id): JsonResponse
    {
        $log = NotificationLog::where('channel', 'email')->findOrFail($id);

        if ($log->status !== 'failed') {
            return response()->json(['message' => 'يمكن إعادة إرسال الرسائل الفاشلة فقط.'], 422);
        }

        $recipient = $log->user?->email ?? $log->recipient;
        $name      = $log->user?->name ?? 'مستخدم';

        if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'بريد المستلم غير صالح.'], 422);
        }

        $log->update([
            'status'        => 'pending',
            'queued_at'     => now(),
            'failed_at'     => null,
            'error_message' => null,
        ]);

        // Re-send as a generic branded email using stored subject/payload
        $subject = $log->subject ?? 'رسالة من منصة EMC';
        $mailable = new WelcomeMail($name, $recipient);

        SendLoggedEmailJob::dispatch($mailable, $recipient, $name, $log->id)->onQueue('emails');

        return response()->json(['message' => 'تمت إعادة جدولة الرسالة في قائمة الانتظار.']);
    }
}
