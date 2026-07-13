<?php

namespace App\Services\Finance;

use App\Mail\PaymentConfirmedMail;
use App\Models\Course;
use App\Models\FinanceAccount;
use App\Models\FinanceAccountTransaction;
use App\Models\ManualPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformNotification;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\EmailService;
use Illuminate\Support\Facades\DB;

class ManualPaymentConfirmationService
{
    public function confirm(ManualPayment $manualPayment, User $reviewer): array
    {
        $result = DB::transaction(function () use ($manualPayment, $reviewer) {
            // 1. Lock and verify status (idempotent guard)
            $mp = ManualPayment::lockForUpdate()->findOrFail($manualPayment->id);

            if ($mp->status !== 'pending_review') {
                throw new \RuntimeException("ManualPayment #{$mp->id} is not pending_review (current: {$mp->status})");
            }

            $student     = User::findOrFail($mp->student_id);
            $purchasable = $mp->purchasable()->first();

            if (! $purchasable) {
                throw new \RuntimeException("Purchasable item not found for ManualPayment #{$mp->id}");
            }

            $purchasableType = $mp->purchasable_type;
            $purchasableId   = $mp->purchasable_id;
            $itemTitle       = $purchasable->title ?? $purchasable->name ?? 'Unknown';

            // Determine category and relation column for the purchasable type
            $isCourse       = str_ends_with($purchasableType, 'Course');
            $isWorkshop     = str_ends_with($purchasableType, 'Workshop');
            $isLearningPath = str_ends_with($purchasableType, 'LearningPath');

            $txnCategory = match (true) {
                $isCourse       => 'course_revenue',
                $isWorkshop     => 'workshop_revenue',
                $isLearningPath => 'learning_path_revenue',
                default         => 'course_revenue',
            };

            // 2. Check for existing payment (idempotency guard)
            $providerPaymentId = 'MANUAL-' . $mp->id;
            $payment = Payment::where('provider', 'manual')
                ->where('provider_payment_id', $providerPaymentId)
                ->first();

            // 3. Create or find Order
            $orderQuery = Order::where('user_id', $student->id)
                ->where('payment_provider', 'manual');

            if ($isCourse) {
                $orderQuery->where('course_id', $purchasableId);
            } else {
                // For workshop/learning path, match on metadata or just use reference
                $orderQuery->where('type', $isWorkshop ? 'workshop' : 'learning_path')
                           ->whereJsonContains('metadata->purchasable_id', $purchasableId);
            }

            $order = $orderQuery->first();

            if (! $order) {
                $orderData = [
                    'user_id'          => $student->id,
                    'order_number'     => 'ORD-MAN-' . strtoupper(substr(md5($mp->id . time()), 0, 8)),
                    'type'             => $isCourse ? 'course' : ($isWorkshop ? 'workshop' : 'learning_path'),
                    'subtotal'         => (float) $mp->paid_amount,
                    'tax_amount'       => 0,
                    'total'            => (float) $mp->paid_amount,
                    'currency'         => $mp->currency,
                    'status'           => 'paid',
                    'payment_provider' => 'manual',
                    'paid_at'          => now(),
                    'metadata'         => ['purchasable_id' => $purchasableId, 'manual_payment_id' => $mp->id],
                ];

                if ($isCourse) {
                    $orderData['course_id'] = $purchasableId;
                }

                $order = Order::create($orderData);
            } else {
                $order->update(['status' => 'paid', 'paid_at' => now()]);
            }

            // 4. Find or create Registration — must exist before Payment is created.
            //    Previous code created Payment first with registration_id = null, causing NOT NULL violation.
            $registration = $this->findRegistration($student->id, $isCourse, $isWorkshop, $isLearningPath, $purchasableId);

            if (! $registration) {
                $regData = [
                    'user_id'   => $student->id,
                    'full_name' => $student->name,
                    'email'     => $student->email,
                    'phone'     => $student->phone ?? null,
                    'status'    => 'pending',
                ];

                if ($isCourse)       { $regData['course_id']        = $purchasableId; }
                if ($isWorkshop)     { $regData['workshop_id']       = $purchasableId; }
                if ($isLearningPath) { $regData['learning_path_id']  = $purchasableId; }

                $registration = Registration::create($regData);
            }

            // 5. Create Payment — Registration is now guaranteed to exist.
            if (! $payment) {
                $payment = Payment::create([
                    'order_id'            => $order->id,
                    'registration_id'     => $registration->id,
                    'amount'              => (float) $mp->paid_amount,
                    'provider'            => 'manual',
                    'provider_payment_id' => $providerPaymentId,
                    'payment_method'      => $mp->payment_method,
                    'status'              => 'confirmed',
                    'confirmed_at'        => now(),
                    'receipt_url'         => $mp->proof_path,
                ]);
            } elseif (! $payment->registration_id) {
                // Heal any orphaned payment created before this fix was deployed.
                $payment->update(['registration_id' => $registration->id]);
            }

            // Activate Registration after payment is confirmed.
            $registration->update(['status' => 'registered']);

            // 5b. Create StudentProgress for course enrollments (idempotent)
            if ($isCourse) {
                $totalSessions = Course::find($purchasableId)?->sessions()->count() ?? 0;
                StudentProgress::firstOrCreate(
                    ['user_id' => $student->id, 'course_id' => $purchasableId],
                    [
                        'progress_percentage' => 0,
                        'completed_sessions'  => 0,
                        'total_sessions'      => $totalSessions,
                        'status'              => 'in_progress',
                    ],
                );
            }

            // 6. Create FinanceAccountTransaction
            $financeAccount = FinanceAccount::lockForUpdate()->findOrFail($mp->finance_account_id);

            // Guard: currency on the payment record must match the account currency.
            // New payments always have the correct currency (set in store()), but this
            // catches any legacy records that were saved before this constraint was enforced.
            if ($mp->currency !== $financeAccount->currency) {
                throw new \RuntimeException(
                    "Currency mismatch on ManualPayment #{$mp->id}: payment currency is '{$mp->currency}' " .
                    "but account '{$financeAccount->name}' is '{$financeAccount->currency}'. " .
                    "Run `php artisan finance:audit-currency-mismatches` to identify and correct inconsistent records."
                );
            }

            $transaction = FinanceAccountTransaction::create([
                'finance_account_id' => $financeAccount->id,
                'type'               => 'revenue',
                'category'           => $txnCategory,
                'amount'             => (float) $mp->paid_amount,
                'currency'           => $financeAccount->currency,
                'status'             => 'confirmed',
                'description'        => "Manual payment confirmed for: {$itemTitle} (#{$mp->id})",
                'payment_method'     => $mp->payment_method,
                'reference_type'     => 'manual_payment',
                'reference_id'       => $mp->id,
                'external_reference' => $mp->external_reference,
                'transaction_date'   => $mp->payment_date,
                'created_by'         => $reviewer->id,
                'reviewed_by'        => $reviewer->id,
                'reviewed_at'        => now(),
            ]);

            // 7. Update account balance
            $financeAccount->increment('current_balance', (float) $mp->paid_amount);

            // 8. Audit log
            AuditLogService::log(
                'manual_payment.confirmed',
                'ManualPayment',
                $mp->id,
                "Manual Payment #{$mp->id} — {$student->name} — {$itemTitle}",
                ['status' => 'pending_review'],
                ['status' => 'confirmed', 'order_id' => $order->id, 'payment_id' => $payment->id, 'finance_transaction_id' => $transaction->id],
            );

            // 9. Update ManualPayment
            $mp->update([
                'status'                 => 'confirmed',
                'order_id'               => $order->id,
                'payment_id'             => $payment->id,
                'finance_transaction_id' => $transaction->id,
                'reviewed_by'            => $reviewer->id,
                'reviewed_at'            => now(),
            ]);

            return compact('order', 'payment', 'registration', 'transaction');
        });

        // 10. In-app notification + email AFTER transaction commits
        $student = User::find($manualPayment->student_id);

        if ($student) {
            $purchasable = $manualPayment->purchasable()->first();
            $itemTitle   = $purchasable?->title ?? $purchasable?->name ?? 'Your purchase';

            $isWs      = str_ends_with($manualPayment->purchasable_type, 'Workshop');
            $notifTitle = $isWs ? 'تم تسجيلك في ورشة جديدة' : 'تم تسجيلك في دورة جديدة';
            $notifMsg   = $isWs
                ? "تم تسجيل دفعتك وإضافتك إلى الورشة «{$itemTitle}»."
                : "تم تسجيل دفعتك وإضافتك إلى الدورة «{$itemTitle}».";

            PlatformNotification::create([
                'user_id'     => $student->id,
                'type'        => $isWs ? 'course_update' : 'course_registration',
                'title'       => $notifTitle,
                'message'     => $notifMsg,
                'entity_type' => $isWs ? 'Workshop' : 'Course',
                'entity_id'   => $manualPayment->purchasable_id,
                'action_url'  => '/dashboard/my-courses',
                'icon'        => 'check-circle',
                'color'       => 'green',
                'is_read'     => false,
                'created_by'  => $manualPayment->updated_by ?? $manualPayment->created_by,
            ]);
        }

        if ($student && $student->email) {
            $purchasable = $manualPayment->purchasable()->first();
            $itemTitle   = $purchasable?->title ?? $purchasable?->name ?? 'Your purchase';

            EmailService::dispatch(
                'payment_confirmed',
                $student->email,
                $student->name,
                $student->id,
                new PaymentConfirmedMail(
                    studentName:   $student->name,
                    courseTitle:   $itemTitle,
                    registrationId: (string) ($result['registration']?->id ?? 0),
                    amount:        number_format((float) $manualPayment->paid_amount, 2),
                    currency:      $manualPayment->currency,
                    paymentMethod: $manualPayment->payment_method ?? 'manual',
                    paidAt:        now()->format('Y-m-d H:i'),
                    dashboardUrl:  rtrim(config('app.frontend_url', config('app.url')), '/') . '/dashboard/my-courses',
                ),
                'تأكيد الدفع وإتمام التسجيل — ' . $itemTitle,
            );
        }

        return $result;
    }

    private function findRegistration(int $userId, bool $isCourse, bool $isWorkshop, bool $isLearningPath, int $purchasableId): ?Registration
    {
        $query = Registration::where('user_id', $userId)->lockForUpdate();

        if ($isCourse)       { return $query->where('course_id', $purchasableId)->first(); }
        if ($isWorkshop)     { return $query->where('workshop_id', $purchasableId)->first(); }
        if ($isLearningPath) { return $query->where('learning_path_id', $purchasableId)->first(); }

        return null;
    }
}
