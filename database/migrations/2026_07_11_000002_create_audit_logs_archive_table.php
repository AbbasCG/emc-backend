<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs_archive', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('user_role')->nullable();
            $table->string('action')->index();
            $table->string('entity_type')->index();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_name')->nullable();
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('changed_fields')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('route', 255)->nullable();
            $table->string('method', 10)->nullable();
            $table->timestamp('original_created_at')->nullable()->index();
            $table->timestamp('archived_at')->useCurrent()->index();

            $table->index(['user_id', 'original_created_at']);
            $table->index(['action', 'original_created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs_archive');
    }
};
