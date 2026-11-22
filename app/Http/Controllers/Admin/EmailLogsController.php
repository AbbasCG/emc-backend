<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendLoggedEmailJob;
use App\Mail\WelcomeMail;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class EmailLogsController extends Controller
{
    // ── GET /api/admin/email-logs ─────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = NotificationLog::where('channel', 'email')
            ->with([
                'user:id,name,email',
                'triggeredBy:id,name',
                'relatedCourse:id,title',
            ])
            ->orderByDesc('created_at');

        // Skipped rows are emails that were deliberately NOT sent (admin switch / user preference).
        // They stay in the table for diagnosis but are hidden unless their status is asked for
        // explicitly — applied here, before pagination, so pages and totals match what is shown.
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } else {
            $query->whereNotIn('status', NotificationLog::SKIPPED_STATUSES);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('search')) {
            $q = $request->input('search');
            $query->where(function ($sub) use ($q) {
                $sub->where('recipient', 'like', "%{$q}%")
                    ->orWhere('recipient_name', 'like', "%{$q}%")
                    ->orWhere('subject', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"))
                    ->orWhereHas('relatedCourse', fn ($c) => $c->where('title', 'like', "%{$q}%"));
            });
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
        if ($request->filled('mail_driver')) {
            $query->where('mail_driver', $request->input('mail_driver'));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->paginate($perPage);

        $items = $paginator->getCollection()->map(fn ($log) => $this->formatLog($log));

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
            ],
        ]);
    }

    // ── GET /api/admin/email-logs/stats ───────────────────────────────────────

    public function stats(): JsonResponse
    {
        $all = NotificationLog::where('channel', 'email');
        // Counters describe emails sent or attempted, like the default list: skipped rows apart.
        $skipped = (clone $all)->whereIn('status', NotificationLog::SKIPPED_STATUSES)->count();
        $base = (clone $all)->whereNotIn('status', NotificationLog::SKIPPED_STATUSES);

        $total      = (clone $base)->count();
        $totalSent  = (clone $base)->where('status', 'sent')->count();
        $sentToday  = (clone $base)->where('status', 'sent')->whereDate('sent_at', today())->count();
        $failedToday = (clone $base)->where('status', 'failed')->whereDate('failed_at', today())->count();
        $pending    = (clone $base)->whereIn('status', ['pending', 'queued', 'sending'])->count();
        $failed     = (clone $base)->where('status', 'failed')->count();

        $avgMs = (clone $base)
            ->where('status', 'sent')
            ->whereNotNull('execution_time_ms')
            ->avg('execution_time_ms');

        $successRate = $total > 0 ? round(($totalSent / $total) * 100, 1) : 0;

        return response()->json([
            'data' => [
                'total'            => $total,
                'total_sent'       => $totalSent,
                'sent_today'       => $sentToday,
                'failed_today'     => $failedToday,
                'pending'          => $pending,
                'failed'           => $failed,
                'success_rate'     => $successRate,
                'avg_delivery_ms'  => $avgMs ? (int) round($avgMs) : null,
                'skipped'          => $skipped,
            ],
        ]);
    }

    // ── GET /api/admin/email-logs/chart ───────────────────────────────────────

    public function chart(): JsonResponse
    {
        $days = (int) request()->query('days', 14);
        $days = max(7, min(90, $days));

        $rows = NotificationLog::where('channel', 'email')
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('DATE(created_at) as date, status, COUNT(*) as cnt')
            ->groupBy('date', 'status')
            ->orderBy('date')
            ->get()
            ->groupBy('date');

        $chart = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date    = now()->subDays($i)->format('Y-m-d');
            $byStatus = $rows->get($date, collect())->pluck('cnt', 'status');
            $sent    = (int) ($byStatus['sent'] ?? 0);
            $failed  = (int) ($byStatus['failed'] ?? 0);
            $pending = (int) ($byStatus['pending'] ?? 0) + (int) ($byStatus['queued'] ?? 0) + (int) ($byStatus['sending'] ?? 0);
            $total   = $sent + $failed + $pending;

            $chart[] = [
                'date'         => $date,
                'sent'         => $sent,
                'failed'       => $failed,
                'pending'      => $pending,
                'total'        => $total,
                'success_rate' => $total > 0 ? round(($sent / $total) * 100) : null,
            ];
        }

        return response()->json(['data' => $chart]);
    }

    // ── GET /api/admin/email-logs/queue ───────────────────────────────────────

    public function queue(): JsonResponse
    {
        $pending  = NotificationLog::where('channel', 'email')->whereIn('status', ['pending', 'queued', 'sending'])->count();
        $failed   = NotificationLog::where('channel', 'email')->where('status', 'failed')->count();
        $retrying = NotificationLog::where('channel', 'email')->where('retry_count', '>', 0)->whereIn('status', ['pending', 'queued', 'sending'])->count();

        // Queue size via DB (works without Redis)
        $queueSize = 0;
        $queueFailed = 0;
        try {
            $queueSize   = (int) DB::table('jobs')->where('queue', 'emails')->count();
            $queueFailed = (int) DB::table('failed_jobs')->where('queue', 'emails')->count();
        } catch (\Throwable) {
            // jobs table may not exist in sync driver
        }

        return response()->json([
            'data' => [
                'pending_logs'  => $pending,
                'failed_logs'   => $failed,
                'retrying'      => $retrying,
                'queue_jobs'    => $queueSize,
                'failed_jobs'   => $queueFailed,
            ],
        ]);
    }

    // ── GET /api/admin/email-logs/{id} ────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $log = NotificationLog::where('channel', 'email')
            ->with(['user:id,name,email', 'triggeredBy:id,name', 'relatedCourse:id,title'])
            ->findOrFail($id);

        return response()->json(['data' => $this->formatLog($log, detailed: true)]);
    }

    // ── POST /api/admin/email-logs/{id}/retry ─────────────────────────────────

    public function retry(int $id): JsonResponse
    {
        $log = NotificationLog::where('channel', 'email')->findOrFail($id);

        if ($log->status !== 'failed') {
            return response()->json(['message' => 'يمكن إعادة إرسال الرسائل الفاشلة فقط.'], 422);
        }

        $recipient = $log->user?->email ?? $log->recipient;
        $name      = $log->recipient_name ?? $log->user?->name ?? 'مستخدم';

        if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'بريد المستلم غير صالح.'], 422);
        }

        if ($log->type !== 'welcome') {
            return response()->json([
                'message' => 'لا يمكن إعادة إرسال هذا النوع تلقائياً. يُرجى إعادة تشغيل العملية الأصلية.',
            ], 422);
        }

        $log->update([
            'status'        => 'pending',
            'queued_at'     => now(),
            'failed_at'     => null,
            'error_message' => null,
            'smtp_response' => null,
        ]);

        $mailable = new WelcomeMail($name, $recipient);
        SendLoggedEmailJob::dispatch($mailable, $recipient, $name, $log->id)->onQueue('emails');

        return response()->json(['message' => 'تمت إعادة جدولة الرسالة.']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatLog(NotificationLog $log, bool $detailed = false): array
    {
        $deliveryMs = null;
        if ($log->sent_at && $log->queued_at) {
            $deliveryMs = (int) ($log->sent_at->diffInMilliseconds($log->queued_at));
        }

        $data = [
            'id'               => $log->id,
            'uuid'             => $log->uuid,
            'status'           => $log->status,
            'type'             => $log->type,
            'recipient'        => $log->recipient,
            'recipient_name'   => $log->recipient_name,
            'subject'          => $log->subject,
            'mail_driver'      => $log->mail_driver,
            'job_id'           => $log->job_id,
            'retry_count'      => $log->retry_count,
            'execution_time_ms' => $log->execution_time_ms,
            'delivery_time_ms' => $deliveryMs,
            'error_message'    => $log->error_message,
            'queued_at'        => $log->queued_at?->toIso8601String(),
            'sent_at'          => $log->sent_at?->toIso8601String(),
            'failed_at'        => $log->failed_at?->toIso8601String(),
            'delivered_at'     => $log->delivered_at?->toIso8601String(),
            'created_at'       => $log->created_at->toIso8601String(),
            'user'             => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name, 'email' => $log->user->email] : null,
            'triggered_by'     => $log->triggeredBy ? ['id' => $log->triggeredBy->id, 'name' => $log->triggeredBy->name] : null,
            'related_course'   => $log->relatedCourse ? ['id' => $log->relatedCourse->id, 'title' => $log->relatedCourse->title] : null,
        ];

        if ($detailed) {
            $data['payload']       = $log->payload;
            $data['smtp_response'] = $log->smtp_response;
        }

        return $data;
    }
}
