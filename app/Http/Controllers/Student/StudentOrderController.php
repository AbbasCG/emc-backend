<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StudentOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::with(['course', 'invoice'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data'    => OrderResource::collection($orders),
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'غير مصرح.'], 403);
        }

        $order->load(['course', 'invoice', 'payments']);

        return response()->json([
            'success' => true,
            'data'    => new OrderResource($order),
        ]);
    }

    public function downloadInvoice(Request $request, Invoice $invoice): JsonResponse|Response
    {
        if ($invoice->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'غير مصرح.'], 403);
        }

        $invoice->load(['order.course', 'user']);

        // Return printable HTML invoice as JSON until PDF engine is ready
        return response()->json([
            'success'  => true,
            'invoice'  => [
                'invoice_number' => $invoice->invoice_number,
                'issued_at'      => $invoice->issued_at?->toDateString(),
                'order'          => [
                    'order_number' => $invoice->order?->order_number,
                    'total'        => $invoice->order?->total,
                    'currency'     => strtoupper($invoice->order?->currency ?? 'EUR'),
                    'status'       => $invoice->order?->status,
                    'paid_at'      => $invoice->order?->paid_at?->toDateTimeString(),
                    'course_title' => $invoice->order?->course?->title,
                ],
                'user' => [
                    'name'  => $invoice->user?->name,
                    'email' => $invoice->user?->email,
                ],
            ],
        ]);
    }
}
