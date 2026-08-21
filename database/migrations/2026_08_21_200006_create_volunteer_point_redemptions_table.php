<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // استبدال النقاط برصيد تعليمي داخلي (1,000 نقطة = €10). غير نقدي أبدًا.
        Schema::create('volunteer_point_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('points');
            $table->string('purpose');                  // دورة/ورشة/برنامج مؤهل
            $table->string('note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_point_redemptions');
    }
};
