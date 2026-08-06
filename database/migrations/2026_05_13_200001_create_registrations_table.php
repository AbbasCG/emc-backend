<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('track_id')->nullable()->constrained('tracks')->nullOnDelete();
            $table->foreignId('workshop_id')->nullable()->constrained('workshops')->nullOnDelete();
            $table->string('full_name');
            $table->string('phone');
            $table->string('email');
            $table->string('city')->nullable();
            $table->string('gender')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('registered');
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('email');
            $table->index('user_id');
            $table->unique(['user_id', 'course_id'], 'registrations_user_course_unique');
            $table->index(['email', 'course_id'], 'registrations_email_course_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
