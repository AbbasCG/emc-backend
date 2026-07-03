<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained()->cascadeOnDelete();
            // type: administrative (الإدارة العليا) | programs (إدارة البرامج)
            $table->string('type');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            // status: pending | approved | rejected
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->index('certificate_id');
            $table->index(['certificate_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_approvals');
    }
};
