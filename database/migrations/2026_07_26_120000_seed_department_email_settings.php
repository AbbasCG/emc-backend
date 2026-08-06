<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now()->toDateTimeString();

        // Only insert keys that do not already exist — never overrides admin toggles.
        // These EmailService::dispatch() keys already existed and were already
        // gated by EmailSetting::isEnabled() (defaults to enabled when no row
        // exists) — this just gives each one a persisted row + a toggle in
        // the admin UI, matching every other configurable email.
        $keys = [
            'ambassador_confirmation',
            'ambassador_admin_notification',
            'ambassador_status_changed',
            'oral_assessment_booking_confirmed',
            'oral_assessment_meeting_link_updated',
            'oral_assessment_instructor_message',
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
