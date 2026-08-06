<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_generation_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_code')->unique();
            $table->string('certificate_type');
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->foreignId('template_id')->nullable()->constrained('certificate_templates')->nullOnDelete();
            $table->string('status')->default('pending');
            // status: pending, processing, completed, failed
            $table->integer('total_recipients')->default(0);
            $table->integer('generated_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_generation_batches');
    }
};
