<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['user', 'course', 'invoice'])
            ->latest();

        if ($search = $request->query('search')) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"))
                ->orWhere('order_number', 'like', "%{$search}%");
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $orders = $query->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'success' => true,
            'data'    => OrderResource::collection($orders->items()),
            'meta'    => [
                'total'        => $orders->total(),
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['user', 'course', 'invoice', 'payments']);

        return response()->json([
            'success' => true,
            'data'    => new OrderResource($order),
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $invoices = Invoice::with(['order.user', 'order.course', 'user'])
            ->latest()
            ->paginate((int) $request->query('per_page', 50));

        $data = $invoices->map(function (Invoice $inv) {
            return [
                'id'             => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'issued_at'      => $inv->issued_at?->toDateString(),
                'order_number'   => $inv->order?->order_number,
                'total'          => $inv->order?->total,
                'currency'       => strtoupper($inv->order?->currency ?? 'EUR'),
                'status'         => $inv->order?->status,
                'course_title'   => $inv->order?->course?->title,
                'student_name'   => $inv->user?->name,
                'student_email'  => $inv->user?->email,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'total'        => $invoices->total(),
                'current_page' => $invoices->currentPage(),
                'last_page'    => $invoices->lastPage(),
            ],
        ]);
    }

    public function downloadInvoice(Invoice $invoice): JsonResponse
    {
        $invoice->load(['order.course', 'user']);

        return response()->json([
            'success' => true,
            'invoice' => [
                'invoice_number' => $invoice->invoice_number,
                'issued_at'      => $invoice->issued_at?->toDateString(),
                'order_number'   => $invoice->order?->order_number,
                'total'          => $invoice->order?->total,
                'currency'       => strtoupper($invoice->order?->currency ?? 'EUR'),
                'status'         => $invoice->order?->status,
                'paid_at'        => $invoice->order?->paid_at?->toDateTimeString(),
                'course_title'   => $invoice->order?->course?->title,
                'student_name'   => $invoice->user?->name,
                'student_email'  => $invoice->user?->email,
            ],
        ]);
    }
}
