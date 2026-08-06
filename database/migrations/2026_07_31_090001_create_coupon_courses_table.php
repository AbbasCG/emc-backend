<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->boolean('excluded')->default(false);
            $table->timestamps();

            // One row per (coupon, course) — `excluded` distinguishes whether
            // this course is included or explicitly excluded for the coupon,
            // rather than needing a second table.
            $table->unique(['coupon_id', 'course_id'], 'coupon_courses_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_courses');
    }
};
