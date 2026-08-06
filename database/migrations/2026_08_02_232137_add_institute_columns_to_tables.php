<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('is_institute')->default(false)->after('type');
        });

        Schema::table('class_groups', function (Blueprint $table) {
            $table->boolean('is_institute')->default(false)->after('course_id');
        });

        Schema::table('placement_tests', function (Blueprint $table) {
            $table->boolean('is_institute')->default(false)->after('title');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('is_institute')->default(false)->after('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('is_institute');
        });

        Schema::table('class_groups', function (Blueprint $table) {
            $table->dropColumn('is_institute');
        });

        Schema::table('placement_tests', function (Blueprint $table) {
            $table->dropColumn('is_institute');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('is_institute');
        });
    }
};
