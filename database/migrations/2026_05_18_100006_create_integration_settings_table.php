<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();         // e.g. whatsapp, stripe, google_calendar
            $table->string('provider')->nullable();  // meta, twilio, stripe, etc.
            $table->text('config');                  // JSON — stored encrypted in model
            $table->string('status')->default('inactive'); // active, inactive
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_settings');
    }
};
