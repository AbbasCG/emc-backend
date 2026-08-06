<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\ContactAdminNotificationMail;
use App\Mail\ContactConfirmationMail;
use App\Models\SupportTicket;
use App\Services\EmailService;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'full_name'    => 'required|string|max:255',
            'email'        => 'required|email|max:255',
            'phone'        => 'required|string|max:30',
            'request_type' => 'required|in:general,partnership,volunteer,technical',
            'subject'      => 'required|string|max:255',
            'message'      => 'required|string|max:5000',
            'priority'     => 'in:low,medium,high,urgent',
        ]);

        $typeMap = [
            'general'     => 'student',
            'partnership' => 'partner',
            'volunteer'   => 'volunteer',
            'technical'   => 'technical',
        ];

        $ticket = SupportTicket::create([
            'user_id'      => $request->user()?->id,
            'full_name'    => $data['full_name'],
            'name'         => $data['full_name'],
            'email'        => $data['email'],
            'phone'        => $data['phone'],
            'request_type' => $data['request_type'],
            'type'         => $typeMap[$data['request_type']] ?? 'student',
            'subject'      => $data['subject'],
            'message'      => $data['message'],
            'priority'     => $data['priority'] ?? 'medium',
            'status'       => 'new',
            'source'       => 'contact_form',
        ]);

        $ticketNumber = $ticket->ticket_number ?? ('#' . $ticket->id);
        $submittedAt  = now()->format('Y-m-d H:i');

        // Confirmation email to requester — queued, never blocks ticket creation.
        try {
            EmailService::dispatch(
                'contact_confirmation',
                $data['email'],
                $data['full_name'],
                $request->user()?->id,
                new ContactConfirmationMail(
                    visitorName:  $data['full_name'],
                    subjectLine:  $data['subject'],
                    body:         $data['message'],
                    submittedAt:  $submittedAt,
                    ticketNumber: $ticketNumber,
                ),
                'تم استلام رسالتك — منصة EMC',
            );
        } catch (\Throwable) {
            // Email failure must never prevent the ticket from being created.
        }

        // Notification email to support team — queued, never blocks ticket creation.
        $supportEmail = (string) config('mail.support_email', 'support@edumc.nl');
        $supportName  = (string) config('mail.support_name', 'EMC Support');

        try {
            EmailService::dispatch(
                'contact_admin_notification',
                $supportEmail,
                $supportName,
                null,
                new ContactAdminNotificationMail(
                    visitorName:  $data['full_name'],
                    visitorEmail: $data['email'],
                    visitorPhone: $data['phone'],
                    subjectLine:  $data['subject'],
                    messageBody:  $data['message'],
                    category:     $typeMap[$data['request_type']] ?? 'student',
                    submittedAt:  $submittedAt,
                    ticketNumber: $ticketNumber,
                ),
                'رسالة جديدة من نموذج التواصل — ' . $data['full_name'],
            );
        } catch (\Throwable) {
            // Email failure must never prevent the ticket from being created.
        }

        return response()->json([
            'success' => true,
            'message' => 'تم استلام طلبك. سيتواصل الفريق معك قريباً.',
        ], 201);
    }
}
