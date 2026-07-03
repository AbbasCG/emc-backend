<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            if (! Schema::hasColumn('certificates', 'workshop_number')) {
                $table->integer('workshop_number')->nullable()->after('metadata');
            }
            if (! Schema::hasColumn('certificates', 'learning_path_id')) {
                $table->foreignId('learning_path_id')
                    ->nullable()
                    ->after('workshop_number')
                    ->constrained('learning_paths')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('certificates', 'approval_status')) {
                // pending_approval | both_approved | rejected
                $table->string('approval_status')->nullable()->default('pending_approval')->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['workshop_number', 'learning_path_id', 'approval_status']);
        });
    }
};
