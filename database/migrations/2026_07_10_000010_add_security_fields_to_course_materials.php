<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            // Secure file metadata
            $table->string('original_filename')->nullable()->after('file_path');
            $table->string('stored_filename')->nullable()->after('original_filename');
            $table->string('mime_type', 127)->nullable()->after('stored_filename');
            $table->string('extension', 20)->nullable()->after('mime_type');
            $table->unsignedBigInteger('size')->nullable()->comment('bytes')->after('extension');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete()->after('size');
            $table->string('disk', 50)->default('private')->after('uploaded_by');
            $table->char('sha256', 64)->nullable()->after('disk');

            // Download tracking
            $table->unsignedInteger('download_count')->default(0)->after('sha256');
            $table->timestamp('last_downloaded_at')->nullable()->after('download_count');

            // Image optimization outputs
            $table->string('thumbnail_path')->nullable()->after('last_downloaded_at');
            $table->string('medium_path')->nullable()->after('thumbnail_path');
            $table->boolean('image_optimized')->default(false)->after('medium_path');

            // Processing pipeline
            $table->string('processing_status', 20)->default('ready')->after('image_optimized');

            // Antivirus
            $table->timestamp('virus_scanned_at')->nullable()->after('processing_status');
            $table->string('virus_status', 20)->nullable()->comment('clean|infected|pending')->after('virus_scanned_at');
        });

        // Index for fast duplicate detection by hash
        Schema::table('course_materials', function (Blueprint $table) {
            $table->index('sha256');
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by']);
            $table->dropIndex(['sha256']);
            $table->dropIndex(['uploaded_by']);
            $table->dropColumn([
                'original_filename', 'stored_filename', 'mime_type', 'extension',
                'size', 'uploaded_by', 'disk', 'sha256', 'download_count',
                'last_downloaded_at', 'thumbnail_path', 'medium_path',
                'image_optimized', 'processing_status',
                'virus_scanned_at', 'virus_status',
            ]);
        });
    }
};
