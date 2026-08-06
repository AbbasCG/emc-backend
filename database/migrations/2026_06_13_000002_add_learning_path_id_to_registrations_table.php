<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->foreignId('learning_path_id')
                ->nullable()
                ->after('workshop_id')
                ->constrained('learning_paths')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\LearningPath::class);
            $table->dropColumn('learning_path_id');
        });
    }
};
