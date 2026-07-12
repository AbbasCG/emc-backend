<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now()->toDateTimeString();

        // Only insert keys that do not already exist — never overrides admin toggles.
        $keys = [
            'contact_confirmation',
            'contact_admin_notification',
            'support_ticket_reply',
            'volunteer_confirmation',
            'volunteer_admin_notification',
            'workshop_request_confirmation',
            'workshop_request_submitted',
            'workshop_request_admin_notification',
            'certificate_ready',
            'certificate_rejected',
            'certificate_revoked',
            'certificate_needs_approval',
            'certificate_pdf_failed',
            'payment_confirmed',
            'account_created',
            'temporary_password',
            'password_reset',
        ];

        foreach ($keys as $key) {
            DB::table('email_settings')->insertOrIgnore([
                'key'        => $key,
                'enabled'    => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Do not delete — these may have been toggled by admins.
    }
};
