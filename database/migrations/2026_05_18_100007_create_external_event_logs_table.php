<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_event_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source');               // stripe, paypal, meta_whatsapp, google_calendar
            $table->string('event_type');           // payment.succeeded, message.received, etc.
            $table->json('payload');
            $table->string('status')->default('received'); // received, processed, failed, ignored
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['source', 'event_type']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_event_logs');
    }
};
