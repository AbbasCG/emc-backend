<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key');                    // registration_confirmed
            $table->string('channel');                // email, whatsapp, in_app
            $table->string('language', 10)->default('en');
            $table->string('subject')->nullable();    // email subject
            $table->text('content');                  // body with {{variable}} placeholders
            $table->string('status')->default('active'); // active, inactive
            $table->timestamps();

            $table->unique(['key', 'channel', 'language']);
            $table->index(['key', 'channel']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
