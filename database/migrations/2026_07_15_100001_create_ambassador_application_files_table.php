<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambassador_application_files', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('ambassador_application_id');
            $table->string('category', 50);              // cv, photo, student_id, certificate, other
            $table->string('original_name', 500);
            $table->string('stored_name', 500);
            $table->string('mime_type', 100);
            $table->string('extension', 20);
            $table->unsignedBigInteger('original_size');  // bytes
            $table->unsignedBigInteger('optimized_size')->nullable();
            $table->decimal('compression_ratio', 5, 2)->nullable(); // percentage saved
            $table->string('checksum', 64);               // sha256
            $table->string('disk', 50)->default('private');
            $table->string('path', 1000);
            $table->string('thumbnail_path', 1000)->nullable();
            $table->string('preview_path', 1000)->nullable();
            $table->enum('processing_status', ['uploaded', 'processing', 'optimized', 'failed', 'skipped'])->default('uploaded');
            $table->text('processing_error')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Foreign keys with short names
            $table->foreign('ambassador_application_id', 'amb_file_app_fk')
                ->references('id')
                ->on('ambassador_applications')
                ->cascadeOnDelete();

            $table->foreign('uploaded_by', 'amb_file_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // Indexes
            $table->index('ambassador_application_id');
            $table->index('category');
            $table->index('processing_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_application_files');
    }
};
