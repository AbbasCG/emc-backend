<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id')->nullable()->index();
            $table->unsignedBigInteger('course_session_id')->nullable()->index();
            $table->foreignId('locked_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('locked_at');

            $table->unique(['session_id', 'course_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_locks');
    }
};
