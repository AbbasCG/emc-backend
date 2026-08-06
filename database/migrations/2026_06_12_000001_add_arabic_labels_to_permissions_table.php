<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            if (! Schema::hasColumn('permissions', 'label_ar')) {
                $table->string('label_ar')->nullable()->after('display_name');
            }
            if (! Schema::hasColumn('permissions', 'description_ar')) {
                $table->text('description_ar')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $existing = array_filter(
                ['label_ar', 'description_ar'],
                fn ($col) => Schema::hasColumn('permissions', $col)
            );
            if ($existing) {
                $table->dropColumn(array_values($existing));
            }
        });
    }
};
