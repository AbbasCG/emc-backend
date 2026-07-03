<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('ticket_number')->nullable()->unique()->after('id');
            $table->string('full_name')->nullable()->after('name');
            $table->string('phone')->nullable()->after('email');
            // general, partnership, volunteer, technical
            $table->string('request_type')->nullable()->after('type');
            $table->timestamp('resolved_at')->nullable()->after('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['ticket_number', 'full_name', 'phone', 'request_type', 'resolved_at']);
        });
    }
};
