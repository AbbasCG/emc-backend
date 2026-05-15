<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FinancialTransactionResource;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Services\KpiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    public function overview(KpiService $kpi): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $kpi->finance(),
        ]);
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
        $payments = Payment::with(['registration.course'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->provider, fn ($q) => $q->where('provider', $request->provider))
            ->when($request->from, fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest()
            ->paginate(30);

        return response()->json([
            'success' => true,
            'data'    => $payments->through(fn ($p) => [
                'id'              => $p->id,
                'amount'          => $p->amount,
                'status'          => $p->status,
                'provider'        => $p->provider,
                'payment_method'  => $p->payment_method,
                'confirmed_at'    => $p->confirmed_at,
                'course'          => $p->registration?->course?->title,
                'student'         => $p->registration?->full_name,
            ]),
        ]);
    }

    public function revenueByCourse(): \Illuminate\Http\JsonResponse
    {
        $data = DB::table('payments')
            ->join('registrations', 'payments.registration_id', '=', 'registrations.id')
            ->join('courses', 'registrations.course_id', '=', 'courses.id')
            ->where('payments.status', 'Confirmed')
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
