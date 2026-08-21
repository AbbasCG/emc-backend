<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultant_applications', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('specialty');                       // مجال الاستشارة
            $table->unsignedTinyInteger('years_experience')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('cv_file_path')->nullable();
            $table->text('motivation')->nullable();            // لماذا تريد الانضمام كمستشار
            $table->string('availability')->nullable();        // ساعات/أسبوع أو وصف حر
            $table->string('status')->default('new');          // new|under_review|interview|accepted|rejected|archived
            $table->text('reviewer_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_applications');
    }
};
