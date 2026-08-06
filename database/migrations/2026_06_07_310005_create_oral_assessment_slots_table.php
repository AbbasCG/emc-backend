<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oral_assessment_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('instructors')->cascadeOnDelete();
            $table->datetime('starts_at');
            $table->datetime('ends_at');
            $table->boolean('is_booked')->default(false);
            $table->foreignId('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('booking_status')->default('available'); // available | booked | completed | cancelled
            $table->string('meeting_link')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oral_assessment_slots');
    }
};
