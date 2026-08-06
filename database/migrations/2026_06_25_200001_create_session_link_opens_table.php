<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_link_opens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->string('session_type')->default('course_session'); // course_session | lms_session
            $table->unsignedBigInteger('course_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            // One row per user per session
            $table->unique(['session_id', 'session_type', 'user_id'], 'slo_unique_session_user');

            $table->index(['session_id', 'session_type']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_link_opens');
    }
};
