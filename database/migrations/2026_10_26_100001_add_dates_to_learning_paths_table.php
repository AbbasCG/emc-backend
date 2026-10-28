<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_paths', function (Blueprint $table) {
            if (! Schema::hasColumn('learning_paths', 'start_date')) {
                $table->date('start_date')->nullable()->after('status');
            }
            if (! Schema::hasColumn('learning_paths', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(['start_date', 'end_date'], fn ($column) => Schema::hasColumn('learning_paths', $column)));
        if ($columns !== []) {
            Schema::table('learning_paths', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
