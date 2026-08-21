<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // تحليلات الزوار الذاتية (بلا خدمات خارجية): مشاهدات الصفحات، أحداث
        // القمع (§17)، وأخطاء الواجهة — كلها خلف موافقة الكوكيز في المتصفح.
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_id', 64)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);                    // pageview | event | error
            $table->string('name')->nullable();            // اسم الحدث أو رسالة الخطأ المختصرة
            $table->string('path', 500)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('locale', 20)->nullable();
            $table->string('timezone', 64)->nullable();    // تقريب «من وين» بلا GeoIP
            $table->string('device', 20)->nullable();      // mobile | tablet | desktop
            $table->string('browser', 40)->nullable();
            $table->string('os', 40)->nullable();
            $table->unsignedSmallInteger('screen_w')->nullable();
            $table->unsignedSmallInteger('screen_h')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at');

            $table->index(['type', 'created_at']);
            $table->index('visitor_id');
            $table->index('path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
