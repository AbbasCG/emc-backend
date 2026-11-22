<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Splits "a ticket was assigned to you" into two independent keys.
 *
 *   ticket_assigned             stays the SUPPORT ticket key — it is the original one
 *                               (SupportTicketController::assign, in-app only, entity_type SupportTicket).
 *   operations_ticket_assigned  new key for operations tickets (App\Models\Ticket). Before this
 *                               migration the operations notification briefly shared ticket_assigned.
 *
 * Everything here keeps today's sending behaviour:
 *   - Support in-app stays on (no admin row = on).
 *   - Support assignment EMAIL is new and did not exist before, so it is written OFF; an admin
 *     turns it on in settings. Any earlier ticket_assigned email switch belonged to the operations
 *     email, so its value moves to operations_ticket_assigned first.
 *   - Operations email and in-app stay on unless an admin had turned that email off.
 *   - Rows already sent keep their links: operations in-app rows (entity_type 'ticket') and operations
 *     email log rows are relabelled to the new key; support in-app rows are untouched.
 *   - Users' saved ticket_assigned preferences are left on the support key (the only place they ever
 *     applied before is the support flow), so nobody's choice is rewritten.
 */
return new class extends Migration
{
    private const OLD = 'ticket_assigned';

    private const OPS = 'operations_ticket_assigned';

    public function up(): void
    {
        $now = now();

        $old = DB::table('email_settings')->where('key', self::OLD)->first();
        if ($old && ! DB::table('email_settings')->where('key', self::OPS)->exists()) {
            DB::table('email_settings')->insert([
                'key' => self::OPS, 'enabled' => (bool) $old->enabled, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        DB::table('email_settings')->updateOrInsert(
            ['key' => self::OLD],
            ['enabled' => false, 'updated_at' => $now, 'created_at' => $old->created_at ?? $now],
        );

        DB::table('platform_notifications')
            ->where('type', self::OLD)
            ->where('entity_type', 'ticket')
            ->update(['type' => self::OPS]);

        // Support tickets never sent an assignment email before, so every logged one is operations.
        DB::table('notification_logs')
            ->where('type', self::OLD)
            ->where('channel', 'email')
            ->update(['type' => self::OPS]);

        foreach ([self::OLD, self::OPS] as $key) {
            Cache::forget("email_setting_{$key}");
        }
    }

    public function down(): void
    {
        DB::table('platform_notifications')->where('type', self::OPS)->update(['type' => self::OLD]);
        DB::table('notification_logs')->where('type', self::OPS)->where('channel', 'email')->update(['type' => self::OLD]);

        $ops = DB::table('email_settings')->where('key', self::OPS)->first();
        if ($ops) {
            DB::table('email_settings')->where('key', self::OLD)->update(['enabled' => (bool) $ops->enabled]);
            DB::table('email_settings')->where('key', self::OPS)->delete();
        }
    }
};
