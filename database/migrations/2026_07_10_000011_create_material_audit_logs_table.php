<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')
                ->constrained('course_materials')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('action', 30); // uploaded | downloaded | deleted | viewed | updated
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable(); // extra context: filename, size, reason, etc.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['material_id', 'action']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_audit_logs');
    }
};
