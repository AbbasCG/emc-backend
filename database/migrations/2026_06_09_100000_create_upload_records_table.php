<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('context', 50);
            $table->char('file_hash', 64)->index();
            $table->string('original_filename');
            $table->unsignedBigInteger('original_size');
            $table->unsignedBigInteger('optimized_size')->nullable();
            $table->string('mime_type', 100);
            $table->string('disk', 20);
            $table->string('path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->text('url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('job_id')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_records');
    }
};
