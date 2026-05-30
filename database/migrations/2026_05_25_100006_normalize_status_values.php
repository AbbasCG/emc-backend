<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── courses ───────────────────────────────────────────────────────────
        // 'active' was the informal name for published courses
        DB::statement("UPDATE courses SET status = 'published' WHERE status = 'active'");

        // ── registrations ─────────────────────────────────────────────────────
        DB::statement("UPDATE registrations SET status = 'registered'        WHERE status = 'Registered'");
        DB::statement("UPDATE registrations SET status = 'pending_payment'   WHERE status = 'Pending Payment'");
        DB::statement("UPDATE registrations SET status = 'payment_confirmed' WHERE status = 'Payment Confirmed'");
        DB::statement("UPDATE registrations SET status = 'attended'          WHERE status = 'Attended'");
        DB::statement("UPDATE registrations SET status = 'no_show'           WHERE status = 'No Show'");
        DB::statement("UPDATE registrations SET status = 'cancelled'         WHERE status = 'Cancelled'");

        // ── payments ──────────────────────────────────────────────────────────
        DB::statement("UPDATE payments SET status = 'pending_payment'   WHERE status = 'Pending Payment'");
        DB::statement("UPDATE payments SET status = 'payment_confirmed' WHERE status = 'Payment Confirmed'");
        DB::statement("UPDATE payments SET status = 'payment_failed'    WHERE status = 'Payment Failed'");
        // ProcessPaymentWebhookJob had a bug — it set 'Payment Pending' (not 'Pending Payment')
        DB::statement("UPDATE payments SET status = 'pending_payment'   WHERE status = 'Payment Pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE courses SET status = 'active' WHERE status = 'published'");

        DB::statement("UPDATE registrations SET status = 'Registered'        WHERE status = 'registered'");
        DB::statement("UPDATE registrations SET status = 'Pending Payment'   WHERE status = 'pending_payment'");
        DB::statement("UPDATE registrations SET status = 'Payment Confirmed' WHERE status = 'payment_confirmed'");
        DB::statement("UPDATE registrations SET status = 'Attended'          WHERE status = 'attended'");
        DB::statement("UPDATE registrations SET status = 'No Show'           WHERE status = 'no_show'");
        DB::statement("UPDATE registrations SET status = 'Cancelled'         WHERE status = 'cancelled'");

        DB::statement("UPDATE payments SET status = 'Pending Payment'   WHERE status = 'pending_payment'");
        DB::statement("UPDATE payments SET status = 'Payment Confirmed' WHERE status = 'payment_confirmed'");
        DB::statement("UPDATE payments SET status = 'Payment Failed'    WHERE status = 'payment_failed'");
    }
};
