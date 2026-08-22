<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // كود مرجعي للمنتج من كتالوج الإكسل (مثل C-101, W-05, F-01) — للتتبع
        // الداخلي والتقارير، بجانب الـslug التقني.
        Schema::table('courses', function (Blueprint $table) {
            $table->string('catalog_code', 40)->nullable()->after('slug')->index();
        });
        Schema::table('learning_paths', function (Blueprint $table) {
            $table->string('catalog_code', 40)->nullable()->after('slug')->index();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('catalog_code');
        });
        Schema::table('learning_paths', function (Blueprint $table) {
            $table->dropColumn('catalog_code');
        });
    }
};
