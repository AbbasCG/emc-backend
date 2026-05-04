<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workshop_requests', function (Blueprint $table) {
            $table->id();
            $table->string('requester_name');
            $table->string('requester_email');
            $table->string('requester_phone')->nullable();
            $table->string('requester_department')->nullable();
            $table->string('program_name');
            $table->string('speaker_name');
            $table->string('speaker_job_title');
            $table->string('speaker_photo')->nullable();
            $table->text('topics');
            $table->json('categories');
            $table->text('target_audience');
            $table->date('proposed_date');
            $table->string('proposed_time');
            $table->json('location_types');
            $table->enum('price_type', ['free', 'paid'])->default('free');
            $table->decimal('price_amount', 10, 2)->nullable();
            $table->text('generated_announcement_text')->nullable();
            $table->string('status')->default('new');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshop_requests');
    }
};
