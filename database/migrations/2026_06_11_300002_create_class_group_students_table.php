<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_group_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('registration_id')->nullable()->index();
            $table->unsignedBigInteger('placement_attempt_id')->nullable()->index();
            $table->unsignedBigInteger('oral_assessment_booking_id')->nullable()->index();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('assigned_at')->useCurrent();
            $table->enum('status', ['assigned', 'moved', 'removed', 'completed'])->default('assigned');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['class_group_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_group_students');
    }
};
