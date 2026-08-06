<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            // executive, department, programs, partnerships, quality, external_partner, general
            $table->string('type')->default('general');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organizer_id')->constrained('users');
            $table->date('meeting_date');
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->string('meeting_url')->nullable();
            // scheduled, in_progress, completed, cancelled
            $table->string('status')->default('scheduled');
            $table->json('agenda')->nullable();
            $table->text('minutes')->nullable();
            $table->json('attendees')->nullable();
            $table->json('files')->nullable();
            $table->string('recording_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
