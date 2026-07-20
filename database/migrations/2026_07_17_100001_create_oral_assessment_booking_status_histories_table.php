<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oral_assessment_booking_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('oral_assessment_booking_id');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('reason')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->foreign('oral_assessment_booking_id', 'oral_booking_status_hist_booking_fk')
                ->references('id')
                ->on('oral_assessment_bookings')
                ->cascadeOnDelete();

            $table->index('oral_assessment_booking_id', 'oral_booking_status_hist_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oral_assessment_booking_status_histories');
    }
};
