<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two independent central controls per email type, replacing the single `enabled` switch's two meanings:
 *
 *   default_enabled  «التفعيل افتراضيًا»: the state for eligible users who FOLLOW the central setting (no personal choice).
 *   paused           «إيقاف الإرسال للجميع مؤقتًا»: stops the type for everyone (even a user who chose «مفعّل»); lifting it
 *                    restores each user's own state. It never edits a personal preference and never replays missed mail.
 *
 * `enabled` is kept (not dropped) and written as `NOT paused` by the application, so a rolled-back application keeps working:
 * the old code reads `enabled` as «is this email allowed to leave at all». down() also writes enabled = 0 for a type that is off by
 * default, so a rollback never starts sending what the administrator had stopped.
 *
 * Backfill, which preserves what is sent today (nothing new starts or stops at deploy time):
 *   enabled = 0 (the old admin switch was OFF: nobody received it)  =>  paused = 1 AND default_enabled = 0
 *   enabled = 1 or no row                                            =>  paused = 0 AND default_enabled = 1 (the column defaults)
 * Nobody receives a type that was off, and the administrator decides deliberately (lift the pause, set the default) when to resume.
 * The sender rows (global_sender_name / global_sender_email) are not email types and are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_settings')) {
            return;
        }

        Schema::table('email_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('email_settings', 'default_enabled')) {
                $table->boolean('default_enabled')->default(true)->after('enabled');
            }
            if (! Schema::hasColumn('email_settings', 'paused')) {
                $table->boolean('paused')->default(false)->after('default_enabled');
            }
        });

        DB::table('email_settings')
            ->where('enabled', false)
            ->whereNotIn('key', ['global_sender_name', 'global_sender_email'])
            ->update(['paused' => true, 'default_enabled' => false]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_settings')) {
            return;
        }

        // The old application reads `enabled` as «allowed to leave at all». A paused type already has enabled = 0. A type that is only OFF
        // BY DEFAULT would otherwise go to everyone after the rollback, so it is switched off for the old application too.
        if (Schema::hasColumn('email_settings', 'default_enabled')) {
            DB::table('email_settings')
                ->where('default_enabled', false)
                ->whereNotIn('key', ['global_sender_name', 'global_sender_email'])
                ->update(['enabled' => false]);
        }

        Schema::table('email_settings', function (Blueprint $table) {
            foreach (['paused', 'default_enabled'] as $column) {
                if (Schema::hasColumn('email_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
