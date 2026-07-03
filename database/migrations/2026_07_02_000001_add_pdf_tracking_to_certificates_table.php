<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            if (! Schema::hasColumn('certificates', 'pdf_generated_at')) {
                $table->timestamp('pdf_generated_at')->nullable()->after('pdf_path');
            }
            if (! Schema::hasColumn('certificates', 'pdf_file_size')) {
                $table->unsignedBigInteger('pdf_file_size')->nullable()->after('pdf_generated_at');
            }
            if (! Schema::hasColumn('certificates', 'pdf_generation_status')) {
                // 'none' | 'pending' | 'generating' | 'ready' | 'failed'
                $table->string('pdf_generation_status', 20)->default('none')->after('pdf_file_size');
            }
            if (! Schema::hasColumn('certificates', 'pdf_last_error')) {
                $table->text('pdf_last_error')->nullable()->after('pdf_generation_status');
            }
            if (! Schema::hasColumn('certificates', 'template_id_used')) {
                // snapshot: which template was used when PDF was last generated
                $table->unsignedBigInteger('template_id_used')->nullable()->after('pdf_last_error');
            }
        });

        // Backfill: mark existing certificates that have a pdf_path as 'ready'
        \Illuminate\Support\Facades\DB::statement("
            UPDATE certificates
            SET pdf_generation_status = 'ready'
            WHERE pdf_path IS NOT NULL AND pdf_generation_status = 'none'
        ");

        \Illuminate\Support\Facades\DB::statement("
            UPDATE certificates
            SET pdf_generation_status = 'failed'
            WHERE status = 'generation_failed' AND pdf_generation_status = 'none'
        ");
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(array_filter([
                Schema::hasColumn('certificates', 'pdf_generated_at')       ? 'pdf_generated_at'       : null,
                Schema::hasColumn('certificates', 'pdf_file_size')          ? 'pdf_file_size'          : null,
                Schema::hasColumn('certificates', 'pdf_generation_status')  ? 'pdf_generation_status'  : null,
                Schema::hasColumn('certificates', 'pdf_last_error')         ? 'pdf_last_error'         : null,
                Schema::hasColumn('certificates', 'template_id_used')       ? 'template_id_used'       : null,
            ]));
        });
    }
};
