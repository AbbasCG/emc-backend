<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->nullable()->constrained('hr_applications')->nullOnDelete();
            $table->string('candidate_name');
            $table->string('candidate_email');
            $table->string('position');
            $table->string('type')->default('online');       // online | in_person | phone
            $table->string('status')->default('scheduled'); // scheduled | completed | cancelled | no_show
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->foreignId('interviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_interviews');
    }
};
