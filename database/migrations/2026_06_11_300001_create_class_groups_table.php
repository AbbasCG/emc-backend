<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instructor_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->string('level_code', 20)->nullable();         // Starter, A1, A2, B1, B2, C1
            $table->string('name', 120);
            $table->unsignedSmallInteger('capacity')->default(20);
            $table->date('start_date')->nullable();
            $table->string('schedule_day', 20)->nullable();        // sunday, monday ...
            $table->time('schedule_time')->nullable();
            $table->string('location_type', 20)->default('online'); // online, in_person, hybrid
            $table->string('meeting_link')->nullable();
            $table->enum('status', ['draft', 'ready', 'active', 'completed', 'archived'])->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_groups');
    }
};
