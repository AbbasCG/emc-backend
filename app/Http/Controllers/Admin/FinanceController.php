<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FinancialTransactionResource;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Support\PaymentStatuses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    public function overview(): \Illuminate\Http\JsonResponse
    {
        try {
            $totalRevenue     = Payment::where('status', PaymentStatuses::CONFIRMED)->sum('amount');
            $confirmedRevenue = $totalRevenue;
            $pendingRevenue   = Payment::where('status', PaymentStatuses::PENDING)->sum('amount');
            $failedCount      = Payment::where('status', PaymentStatuses::FAILED)->count();

            $monthlyRevenue = DB::table('payments')
                ->where('status', PaymentStatuses::CONFIRMED)
                ->whereNotNull('confirmed_at')
                ->select(
                    DB::raw("DATE_FORMAT(confirmed_at, '%Y-%m') as month"),
                    DB::raw('SUM(amount) as amount'),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('month')
                ->orderBy('month', 'desc')
                ->limit(12)
                ->get()
                ->map(fn ($r) => ['month' => $r->month, 'amount' => (float) $r->amount])
                ->values();

            $revenueByCourse = DB::table('payments')
                ->join('registrations', 'payments.registration_id', '=', 'registrations.id')
                ->join('courses', 'registrations.course_id', '=', 'courses.id')
                ->where('payments.status', PaymentStatuses::CONFIRMED)
                ->select('courses.title', DB::raw('SUM(payments.amount) as amount'))
                ->groupBy('courses.id', 'courses.title')
                ->orderByDesc('amount')
                ->limit(10)
                ->get()
                ->map(fn ($r) => ['course_name' => $r->title, 'amount' => (float) $r->amount])
                ->values();

            $revenueByTrack = DB::table('payments')
                ->join('registrations', 'payments.registration_id', '=', 'registrations.id')
                ->join('tracks', 'registrations.track_id', '=', 'tracks.id')
                ->where('payments.status', PaymentStatuses::CONFIRMED)
                ->select('tracks.name', DB::raw('SUM(payments.amount) as amount'))
                ->groupBy('tracks.id', 'tracks.name')
                ->orderByDesc('amount')
                ->limit(10)
                ->get()
                ->map(fn ($r) => ['track_name' => $r->name, 'amount' => (float) $r->amount])
                ->values();

            $latestPayments = Payment::with(['registration.course'])
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn ($p) => [
                    'id'          => $p->id,
                    'amount'      => $p->amount,
                    'currency'    => strtoupper($p->registration?->course?->currency ?? 'eur'),
                    'status'      => strtolower($p->status ?? 'pending'),
                    'provider'    => $p->provider ?? 'unknown',
                    'course_name' => $p->registration?->course?->title,
                    'payer_email' => $p->registration?->email,
                    'created_at'  => $p->created_at?->toIso8601String(),
                ])
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_revenue'     => (float) $totalRevenue,
                    'confirmed_revenue' => (float) $confirmedRevenue,
                    'pending_revenue'   => (float) $pendingRevenue,
                    'failed_count'      => $failedCount,
                    'monthly_revenue'   => $monthlyRevenue,
                    'revenue_by_course' => $revenueByCourse,
                    'revenue_by_track'  => $revenueByTrack,
                    'latest_payments'   => $latestPayments,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => true,
                'data' => [
                    'total_revenue'     => 0,
                    'confirmed_revenue' => 0,
                    'pending_revenue'   => 0,
                    'failed_count'      => 0,
                    'monthly_revenue'   => [],
                    'revenue_by_course' => [],
                    'revenue_by_track'  => [],
                    'latest_payments'   => [],
                ],
            ]);
        }
    }

    public function transactions(Request $request): \Illuminate\Http\JsonResponse
    {
        $transactions = FinancialTransaction::with(['user', 'payment'])
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->from, fn ($q) => $q->whereDate('occurred_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('occurred_at', '<=', $request->to))
            ->latest('occurred_at')
            ->paginate(30);

        return response()->json([
            'success' => true,
            'data'    => FinancialTransactionResource::collection($transactions)->response()->getData(true),
        ]);
    }

    public function payments(Request $request): \Illuminate\Http\JsonResponse
    {
        $payments = Payment::with([
                'registration.course',
                'order.user',
                'order.course',
                'order.invoice',
            ])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->provider, fn ($q) => $q->where('provider', $request->provider))
            ->when($request->from, fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->when($request->search, function ($q) use ($request) {
                $s = $request->search;
                $q->whereHas('order', fn ($oq) => $oq->where('order_number', 'like', "%{$s}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%")))
                  ->orWhereHas('registration', fn ($rq) => $rq->where('full_name', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%"));
            })
            ->latest()
            ->paginate(30);

        return response()->json([
            'success' => true,
            'data'    => $payments->through(function ($p) {
                // Resolve student name/email from order.user (Stripe flow) or registration (legacy)
                $studentName  = $p->order?->user?->name  ?? $p->registration?->full_name  ?? null;
                $studentEmail = $p->order?->user?->email ?? $p->registration?->email       ?? null;
                $courseTitle  = $p->order?->course?->title ?? $p->registration?->course?->title ?? null;
                $currency     = strtoupper($p->order?->currency ?? $p->registration?->course?->currency ?? 'eur');
                $orderNumber  = $p->order?->order_number ?? null;
                $invoiceNum   = $p->order?->invoice?->invoice_number ?? null;

                return [
                    'id'              => $p->id,
                    'amount'          => $p->amount,
                    'currency'        => $currency,
                    'status'          => $p->status,
                    'provider'        => $p->provider,
                    'payment_method'  => $p->payment_method,
                    'receipt_url'     => $p->receipt_url ?? null,
                    'confirmed_at'    => $p->confirmed_at,
                    'created_at'      => $p->created_at,
                    'student_name'    => $studentName,
                    'student_email'   => $studentEmail,
                    'course'          => $courseTitle,
                    'order_number'    => $orderNumber,
                    'invoice_number'  => $invoiceNum,
                    'order_id'        => $p->order_id,
                    'registration_id' => $p->registration_id,
                ];
            }),
        ]);
    }

    public function revenueByCourse(): \Illuminate\Http\JsonResponse
    {
        $data = DB::table('payments')
            ->join('registrations', 'payments.registration_id', '=', 'registrations.id')
            ->join('courses', 'registrations.course_id', '=', 'courses.id')
            ->where('payments.status', PaymentStatuses::CONFIRMED)
            ->select(
                'courses.id',
                'courses.title',
                DB::raw('SUM(payments.amount) as total_revenue'),
                DB::raw('COUNT(payments.id) as payment_count'),
                DB::raw('AVG(payments.amount) as avg_payment')
            )
            ->groupBy('courses.id', 'courses.title')
            ->orderByDesc('total_revenue')
            ->get();

        return response()->json(['success' => true, 'data' => $data]);
    }
}
