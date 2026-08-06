<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workshop_id')->nullable()->constrained('workshops')->nullOnDelete();
            // workshop_attendance | course_completion | track_completion | professional_certificate
            $table->string('certificate_type');
            $table->string('title');
            $table->string('professional_title')->nullable();
            $table->string('verification_code')->unique();
            $table->string('qr_code_path')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            // pending | approved | issued | revoked
            $table->string('status')->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('verification_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
