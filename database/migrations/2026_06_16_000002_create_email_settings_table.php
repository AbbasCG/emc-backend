<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(true);
            $table->string('sender_name')->nullable();
            $table->string('sender_email')->nullable();
            $table->timestamps();
        });

        $defaults = [
            ['key' => 'welcome',                    'enabled' => 1],
            ['key' => 'course_enrollment',          'enabled' => 1],
            ['key' => 'learning_path_enrollment',   'enabled' => 1],
            ['key' => 'course_completion',          'enabled' => 1],
            ['key' => 'learning_path_completion',   'enabled' => 1],
            ['key' => 'assignment_submitted',       'enabled' => 1],
            ['key' => 'assignment_graded',          'enabled' => 1],
            ['key' => 'session_reminder',           'enabled' => 1],
            ['key' => 'certificate_issued',         'enabled' => 1],
            ['key' => 'admin_new_registration',     'enabled' => 1],
            ['key' => 'global_sender_name',         'enabled' => 1, 'sender_name' => null],
            ['key' => 'global_sender_email',        'enabled' => 1, 'sender_email' => null],
        ];

        $now = now()->toDateTimeString();
        foreach ($defaults as $row) {
            DB::table('email_settings')->insertOrIgnore(array_merge(
                $row,
                ['created_at' => $now, 'updated_at' => $now]
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_settings');
    }
};
