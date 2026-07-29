<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->string('request_type', 100)->default('احتياج وظيفي / نقص أعضاء');
            $table->integer('required_count')->default(1);
            $table->text('skills_and_specs');
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'in_progress'])->default('pending');
            $table->enum('target_audience', ['hr', 'top_management'])->default('hr');
            $table->text('hr_feedback')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_requests');
    }
};
